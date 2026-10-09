<?php

declare(strict_types=1);

namespace Muninn\Api\Broadcasts;

use DateTimeImmutable;
use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use PDO;
use PDOException;
use Throwable;

/**
 * Administrator broadcast messages (decision D061).
 *
 * The system administrator schedules a message with a start and an end. While it runs, every
 * signed-in everyday user sees it until they are done with it:
 *   once   seen once, then gone;
 *   sticky shown on every page until closed with its X;
 *   vote   shown until the user has voted (one or several of 2-5 options).
 * "Done" is one row in broadcast_receipts per user and broadcast, so it holds on every device.
 */
final class BroadcastService
{
    public const KIND_ONCE = 'once';
    public const KIND_STICKY = 'sticky';
    public const KIND_VOTE = 'vote';
    public const KINDS = [self::KIND_ONCE, self::KIND_STICKY, self::KIND_VOTE];

    public const MINIMUM_OPTIONS = 2;
    public const MAXIMUM_OPTIONS = 5;

    /** The administrator's list keeps the newest broadcasts; older ones stay in the database. */
    private const ADMIN_LIST_LIMIT = 200;

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * The broadcasts showing to this user right now: started, not ended, and not yet seen,
     * closed or voted on by them. Oldest start first, so the order stays stable between pages.
     *
     * @return list<array<string, mixed>>
     */
    public function listShowingForUser(string $userId): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT broadcasts.id, broadcasts.kind, broadcasts.message, broadcasts.allows_multiple_choices,
                    broadcasts.starts_at, broadcasts.ends_at
             FROM broadcasts
             LEFT JOIN broadcast_receipts
                    ON broadcast_receipts.broadcast_id = broadcasts.id AND broadcast_receipts.user_id = :user_id
             WHERE broadcasts.starts_at <= UTC_TIMESTAMP() AND broadcasts.ends_at > UTC_TIMESTAMP()
               AND broadcast_receipts.user_id IS NULL
             ORDER BY broadcasts.starts_at, broadcasts.id
             LIMIT 20'
        );
        $selectStatement->execute(['user_id' => $userId]);
        $broadcastRows = $selectStatement->fetchAll();
        $optionsByBroadcast = $this->optionsFor(array_column($broadcastRows, 'id'));

        return array_map(
            static fn (array $broadcastRow): array => [
                'id' => $broadcastRow['id'],
                'kind' => $broadcastRow['kind'],
                'message' => $broadcastRow['message'],
                'allows_multiple_choices' => (bool) $broadcastRow['allows_multiple_choices'],
                'starts_at' => UtcTimestamp::toIso((string) $broadcastRow['starts_at']),
                'ends_at' => UtcTimestamp::toIso((string) $broadcastRow['ends_at']),
                // Users see the answers, never how others voted.
                'options' => array_map(
                    static fn (array $optionRow): array => ['id' => $optionRow['id'], 'label' => $optionRow['label']],
                    $optionsByBroadcast[$broadcastRow['id']] ?? [],
                ),
            ],
            $broadcastRows,
        );
    }

    /**
     * Records that the user is done with a one-time or sticky banner. Doing it twice is fine.
     *
     * @param string $expectedKind KIND_ONCE ("seen") or KIND_STICKY ("closed").
     * @throws HttpException 404 when no such broadcast of that kind is showing right now.
     */
    public function markDone(string $broadcastId, string $userId, string $expectedKind): void
    {
        $this->requireShowing($broadcastId, $expectedKind);

        $insertStatement = $this->database->prepare(
            'INSERT IGNORE INTO broadcast_receipts (broadcast_id, user_id, responded_at)
             VALUES (:broadcast_id, :user_id, UTC_TIMESTAMP())'
        );
        $insertStatement->execute(['broadcast_id' => $broadcastId, 'user_id' => $userId]);
    }

    /**
     * Stores a user's vote. A user votes once per broadcast and cannot change it afterwards.
     *
     * @param list<string> $chosenOptionIds
     * @return array{message: string, chosen_labels: list<string>} For the administrator's notification.
     * @throws HttpException 404 when the vote is not showing, 422 for invalid choices, 409 when already voted.
     */
    public function vote(string $broadcastId, string $userId, array $chosenOptionIds): array
    {
        $broadcastRow = $this->requireShowing($broadcastId, self::KIND_VOTE);

        $uniqueOptionIds = array_values(array_unique($chosenOptionIds));
        if ($uniqueOptionIds === []) {
            throw HttpException::validation(['option_ids' => 'Choose an answer.']);
        }
        if (!$broadcastRow['allows_multiple_choices'] && count($uniqueOptionIds) > 1) {
            throw HttpException::validation(['option_ids' => 'Choose only one answer.']);
        }

        // Every chosen option must belong to this vote; labels are kept in the vote's own order.
        $labelsByOptionId = [];
        foreach ($this->optionsFor([$broadcastId])[$broadcastId] ?? [] as $optionRow) {
            $labelsByOptionId[$optionRow['id']] = $optionRow['label'];
        }
        foreach ($uniqueOptionIds as $chosenOptionId) {
            if (!isset($labelsByOptionId[$chosenOptionId])) {
                throw HttpException::validation(['option_ids' => 'Choose one of the answers shown.']);
            }
        }

        $this->database->beginTransaction();
        try {
            // The receipt's primary key makes a second vote fail, even from two tabs at once.
            $receiptStatement = $this->database->prepare(
                'INSERT INTO broadcast_receipts (broadcast_id, user_id, responded_at)
                 VALUES (:broadcast_id, :user_id, UTC_TIMESTAMP())'
            );
            $receiptStatement->execute(['broadcast_id' => $broadcastId, 'user_id' => $userId]);

            $voteStatement = $this->database->prepare(
                'INSERT INTO broadcast_votes (option_id, user_id, voted_at) VALUES (:option_id, :user_id, UTC_TIMESTAMP())'
            );
            foreach ($uniqueOptionIds as $chosenOptionId) {
                $voteStatement->execute(['option_id' => $chosenOptionId, 'user_id' => $userId]);
            }
            $this->database->commit();
        } catch (PDOException $insertFailure) {
            $this->database->rollBack();
            // 23000: integrity constraint violation, here the duplicate receipt.
            if ($insertFailure->getCode() === '23000') {
                throw HttpException::conflict('already_voted', 'You have already voted.');
            }
            throw $insertFailure;
        } catch (Throwable $otherFailure) {
            $this->database->rollBack();
            throw $otherFailure;
        }

        return [
            'message' => (string) $broadcastRow['message'],
            'chosen_labels' => array_values(array_filter(
                $labelsByOptionId,
                static fn (string $optionId): bool => in_array($optionId, $uniqueOptionIds, true),
                ARRAY_FILTER_USE_KEY,
            )),
        ];
    }

    /**
     * Creates a broadcast (and its vote options) and returns its ID.
     *
     * @param list<string> $optionLabels Votes only; already validated.
     */
    public function create(
        string $kind,
        string $message,
        bool $allowsMultipleChoices,
        DateTimeImmutable $startsAtUtc,
        DateTimeImmutable $endsAtUtc,
        array $optionLabels,
        string $administratorUserId,
    ): string {
        $broadcastId = UuidGenerator::generate();

        $this->database->beginTransaction();
        try {
            $insertStatement = $this->database->prepare(
                'INSERT INTO broadcasts (id, kind, message, allows_multiple_choices, starts_at, ends_at,
                                         created_by_user_id, created_at, updated_at)
                 VALUES (:id, :kind, :message, :allows_multiple_choices, :starts_at, :ends_at,
                         :created_by_user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $insertStatement->execute([
                'id' => $broadcastId,
                'kind' => $kind,
                'message' => $message,
                'allows_multiple_choices' => $kind === self::KIND_VOTE && $allowsMultipleChoices ? 1 : 0,
                'starts_at' => $startsAtUtc->format('Y-m-d H:i:s'),
                'ends_at' => $endsAtUtc->format('Y-m-d H:i:s'),
                'created_by_user_id' => $administratorUserId,
            ]);

            $optionStatement = $this->database->prepare(
                'INSERT INTO broadcast_options (id, broadcast_id, position, label) VALUES (:id, :broadcast_id, :position, :label)'
            );
            foreach (array_values($optionLabels) as $optionIndex => $optionLabel) {
                $optionStatement->execute([
                    'id' => UuidGenerator::generate(),
                    'broadcast_id' => $broadcastId,
                    'position' => $optionIndex + 1,
                    'label' => $optionLabel,
                ]);
            }
            $this->database->commit();
        } catch (Throwable $insertFailure) {
            $this->database->rollBack();
            throw $insertFailure;
        }

        return $broadcastId;
    }

    /**
     * Changes the text and schedule. The kind and the vote's options never change, so votes
     * already given always mean what the voter saw.
     *
     * @throws HttpException 404 when the broadcast does not exist.
     */
    public function update(string $broadcastId, string $message, DateTimeImmutable $startsAtUtc, DateTimeImmutable $endsAtUtc): void
    {
        $this->requireExisting($broadcastId);
        $updateStatement = $this->database->prepare(
            'UPDATE broadcasts SET message = :message, starts_at = :starts_at, ends_at = :ends_at, updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $updateStatement->execute([
            'id' => $broadcastId,
            'message' => $message,
            'starts_at' => $startsAtUtc->format('Y-m-d H:i:s'),
            'ends_at' => $endsAtUtc->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Deletes a broadcast with its options, receipts and votes (ON DELETE CASCADE).
     *
     * @throws HttpException 404 when the broadcast does not exist.
     */
    public function delete(string $broadcastId): void
    {
        $this->requireExisting($broadcastId);
        $deleteStatement = $this->database->prepare('DELETE FROM broadcasts WHERE id = :id');
        $deleteStatement->execute(['id' => $broadcastId]);
    }

    /**
     * One broadcast as the administrator sees it.
     *
     * @return array<string, mixed>
     * @throws HttpException 404 when the broadcast does not exist.
     */
    public function findForAdministrator(string $broadcastId): array
    {
        $this->requireExisting($broadcastId);

        return $this->administratorRows('WHERE broadcasts.id = :id', ['id' => $broadcastId])[0];
    }

    /**
     * Every broadcast, newest start first, with how many users are done with it and, for votes,
     * the results with who picked what.
     *
     * @return list<array<string, mixed>>
     */
    public function listForAdministrator(): array
    {
        return $this->administratorRows('', []);
    }

    /**
     * @param array<string, string> $parameters
     * @return list<array<string, mixed>>
     */
    private function administratorRows(string $whereClause, array $parameters): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT broadcasts.id, broadcasts.kind, broadcasts.message, broadcasts.allows_multiple_choices,
                    broadcasts.starts_at, broadcasts.ends_at, broadcasts.created_at, broadcasts.updated_at,
                    (broadcasts.starts_at > UTC_TIMESTAMP()) AS is_scheduled,
                    (broadcasts.ends_at <= UTC_TIMESTAMP()) AS has_ended,
                    (SELECT COUNT(*) FROM broadcast_receipts WHERE broadcast_receipts.broadcast_id = broadcasts.id) AS done_count
             FROM broadcasts
             ' . $whereClause . '
             ORDER BY broadcasts.starts_at DESC, broadcasts.id
             LIMIT ' . self::ADMIN_LIST_LIMIT
        );
        $selectStatement->execute($parameters);
        $broadcastRows = $selectStatement->fetchAll();
        $broadcastIds = array_column($broadcastRows, 'id');
        $optionsByBroadcast = $this->optionsFor($broadcastIds);
        $votersByOption = $this->votersFor($broadcastIds);

        $administratorRows = [];
        foreach ($broadcastRows as $broadcastRow) {
            $status = $broadcastRow['is_scheduled'] ? 'scheduled' : ($broadcastRow['has_ended'] ? 'ended' : 'showing');
            $administratorRows[] = [
                'id' => $broadcastRow['id'],
                'kind' => $broadcastRow['kind'],
                'message' => $broadcastRow['message'],
                'allows_multiple_choices' => (bool) $broadcastRow['allows_multiple_choices'],
                'starts_at' => UtcTimestamp::toIso((string) $broadcastRow['starts_at']),
                'ends_at' => UtcTimestamp::toIso((string) $broadcastRow['ends_at']),
                'created_at' => UtcTimestamp::toIso((string) $broadcastRow['created_at']),
                'updated_at' => UtcTimestamp::toIso((string) $broadcastRow['updated_at']),
                'status' => $status,
                // Users who saw it (once), closed it (sticky) or voted (vote).
                'done_count' => (int) $broadcastRow['done_count'],
                'options' => array_map(
                    static fn (array $optionRow): array => [
                        'id' => $optionRow['id'],
                        'label' => $optionRow['label'],
                        'vote_count' => count($votersByOption[$optionRow['id']] ?? []),
                        'voters' => $votersByOption[$optionRow['id']] ?? [],
                    ],
                    $optionsByBroadcast[$broadcastRow['id']] ?? [],
                ),
            ];
        }

        return $administratorRows;
    }

    /**
     * The options of the given broadcasts, grouped by broadcast, in position order.
     *
     * @param list<string> $broadcastIds
     * @return array<string, list<array{id: string, label: string}>>
     */
    private function optionsFor(array $broadcastIds): array
    {
        if ($broadcastIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($broadcastIds), '?'));
        $selectStatement = $this->database->prepare(
            'SELECT id, broadcast_id, label FROM broadcast_options
             WHERE broadcast_id IN (' . $placeholders . ')
             ORDER BY broadcast_id, position'
        );
        $selectStatement->execute(array_values($broadcastIds));

        $optionsByBroadcast = [];
        foreach ($selectStatement->fetchAll() as $optionRow) {
            $optionsByBroadcast[$optionRow['broadcast_id']][] = ['id' => (string) $optionRow['id'], 'label' => (string) $optionRow['label']];
        }

        return $optionsByBroadcast;
    }

    /**
     * Who picked each option of the given broadcasts (display names, for the administrator only).
     *
     * @param list<string> $broadcastIds
     * @return array<string, list<array{username: string, display_name: string}>>
     */
    private function votersFor(array $broadcastIds): array
    {
        if ($broadcastIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($broadcastIds), '?'));
        $selectStatement = $this->database->prepare(
            'SELECT broadcast_votes.option_id, users.username, users.display_name
             FROM broadcast_votes
             JOIN broadcast_options ON broadcast_options.id = broadcast_votes.option_id
             JOIN users ON users.id = broadcast_votes.user_id
             WHERE broadcast_options.broadcast_id IN (' . $placeholders . ')
             ORDER BY broadcast_votes.voted_at, users.username'
        );
        $selectStatement->execute(array_values($broadcastIds));

        $votersByOption = [];
        foreach ($selectStatement->fetchAll() as $voteRow) {
            $votersByOption[$voteRow['option_id']][] = [
                'username' => (string) $voteRow['username'],
                'display_name' => (string) $voteRow['display_name'],
            ];
        }

        return $votersByOption;
    }

    /**
     * @return array<string, mixed> The broadcast row.
     * @throws HttpException 404 when it is not showing right now or is of another kind. Ended and
     *                       future broadcasts look exactly like unknown ones.
     */
    private function requireShowing(string $broadcastId, string $expectedKind): array
    {
        if (!UuidGenerator::isValid($broadcastId)) {
            throw HttpException::notFound();
        }
        $selectStatement = $this->database->prepare(
            'SELECT id, kind, message, allows_multiple_choices FROM broadcasts
             WHERE id = :id AND kind = :kind AND starts_at <= UTC_TIMESTAMP() AND ends_at > UTC_TIMESTAMP()'
        );
        $selectStatement->execute(['id' => $broadcastId, 'kind' => $expectedKind]);
        $broadcastRow = $selectStatement->fetch();
        if ($broadcastRow === false) {
            throw HttpException::notFound();
        }

        return $broadcastRow;
    }

    /** @throws HttpException 404 when the broadcast does not exist. */
    private function requireExisting(string $broadcastId): void
    {
        if (!UuidGenerator::isValid($broadcastId)) {
            throw HttpException::notFound();
        }
        $selectStatement = $this->database->prepare('SELECT 1 FROM broadcasts WHERE id = :id');
        $selectStatement->execute(['id' => $broadcastId]);
        if ($selectStatement->fetchColumn() === false) {
            throw HttpException::notFound();
        }
    }
}
