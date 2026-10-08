/*
 * Muninn Markdown renderer (decision D035, approved 2026-10-08).
 *
 * Note content is untrusted, even in your own workspace: shared workspaces make stored XSS
 * dangerous (SECURITY.md, "XSS and Markdown"). Rendering therefore happens in three layers:
 *
 *   1. marked turns Markdown into HTML with raw HTML switched OFF: any HTML typed into a note
 *      is shown as text, never interpreted.
 *   2. Images only render when they point at a Muninn attachment ("attachment:<id>"). Every
 *      other image URL is shown as a plain link instead (D036: no tracking pixels, no mixed
 *      content, nothing loads from outside without a click).
 *   3. DOMPurify sanitises the result before it reaches the page, removing scripts, event
 *      attributes and javascript: URLs whatever layers 1 and 2 let through. The page's CSP
 *      (no inline scripts) is the last line of defence.
 *
 * The sanitised result is inserted as a DOM fragment, never through innerHTML.
 *
 * Code blocks are coloured by highlight.js (D054) inside layer 1: it escapes the code itself and
 * adds only <span class="hljs-…"> tags, and its output still goes through DOMPurify. Pages that
 * do not load highlight.js simply show plain code blocks.
 */
(function () {
    'use strict';

    var apiBaseUrl = (document.querySelector('meta[name="muninn-api-base"]') || { getAttribute: function () { return ''; } })
        .getAttribute('content').replace(/\/+$/, '');

    /**
     * Every rendered image must start with this URL; anything else is removed by the sanitiser.
     * The Magic Link page (D059) loads images through the visitor endpoint instead, which it
     * names in a <meta name="muninn-attachment-path"> tag; only these two paths are possible.
     */
    var attachmentPathMeta = document.querySelector('meta[name="muninn-attachment-path"]');
    var attachmentPath = attachmentPathMeta && attachmentPathMeta.getAttribute('content') === '/api/v1/link/attachments/'
        ? '/api/v1/link/attachments/'
        : '/api/v1/attachments/';
    var attachmentUrlPrefix = apiBaseUrl + attachmentPath;

    /** "attachment:<uuid>" — the only image source notes may use. */
    var attachmentReferencePattern = /^attachment:([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/;

    /**
     * Matches a task-list line ("- [ ] milk", "1. [x] done", "> - [ ] quoted") and captures the
     * part before the box, the box state and the rest. Used to tick boxes in the Markdown source.
     */
    var taskLinePattern = /^((?:[ \t]*>)*[ \t]*(?:[-*+]|\d{1,9}[.)])[ \t]+\[)([ xX])(\](?:[ \t]|$))/;

    /** Escapes text for use inside HTML (layer 1 builds a few tags by hand). */
    function escapeHtml(unsafeText) {
        return String(unsafeText)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /** The URL an attachment reference is served from, or null when it is not one. */
    function attachmentUrl(imageReference) {
        var referenceMatch = attachmentReferencePattern.exec(String(imageReference).trim());
        return referenceMatch ? attachmentUrlPrefix + referenceMatch[1] + '/content' : null;
    }

    /** Auto-detected languages below this highlight.js relevance score are shown as plain text. */
    var minimumAutoDetectRelevance = 5;

    /**
     * Returns the HTML for a fenced or indented code block: highlighted when highlight.js is
     * loaded and knows the language (or can tell it with confidence), otherwise escaped plain text.
     */
    function renderCodeBlock(codeText, languageInfo) {
        // Only the first word of the info string ("php startinline") names the language.
        var languageName = String(languageInfo || '').trim().split(/\s+/)[0].toLowerCase();
        var highlighter = window.hljs;
        var highlightedHtml = null;
        var detectedLanguage = '';

        if (highlighter && languageName !== '' && highlighter.getLanguage(languageName)) {
            highlightedHtml = highlighter.highlight(codeText, { language: languageName, ignoreIllegals: true }).value;
            detectedLanguage = languageName;
        } else if (highlighter && languageName === '') {
            // No language given: guess, but only show colours when the guess is confident.
            var autoResult = highlighter.highlightAuto(codeText);
            if (autoResult.relevance >= minimumAutoDetectRelevance) {
                highlightedHtml = autoResult.value;
                detectedLanguage = autoResult.language || '';
            }
        }

        var languageClass = /^[a-z0-9+#-]+$/.test(detectedLanguage) ? ' language-' + detectedLanguage : '';
        if (highlightedHtml === null) {
            return '<pre><code>' + escapeHtml(codeText) + '</code></pre>\n';
        }
        return '<pre><code class="hljs' + escapeHtml(languageClass) + '">' + highlightedHtml + '</code></pre>\n';
    }

    var markdownParser = new window.marked.Marked({
        gfm: true,
        // A single line break in a note is a line break, as people expect when typing on a phone.
        breaks: true,
    });

    markdownParser.use({
        renderer: {
            // Layer 1: raw HTML in a note is displayed as the text it is.
            html: function (htmlToken) {
                return escapeHtml(htmlToken.text);
            },
            // Code blocks, coloured when possible (D054).
            code: function (codeToken) {
                return renderCodeBlock(codeToken.text, codeToken.lang);
            },
            // Layer 2: only Muninn attachments become images (D036).
            image: function (imageToken) {
                var altText = escapeHtml(imageToken.text || '');
                var imageUrl = attachmentUrl(imageToken.href);
                if (imageUrl !== null) {
                    return '<img src="' + escapeHtml(imageUrl) + '" alt="' + altText + '" class="muninn-note-image">';
                }
                var label = (imageToken.text || 'image') + ' (external image not shown)';
                return '<a href="' + escapeHtml(imageToken.href) + '" class="muninn-external-image">' + escapeHtml(label) + '</a>';
            },
        },
    });

    // Layer 3 extras, applied to every element DOMPurify keeps.
    window.DOMPurify.addHook('afterSanitizeAttributes', function (sanitisedNode) {
        var tagName = sanitisedNode.nodeName;
        if (tagName === 'A' && sanitisedNode.hasAttribute('href')) {
            // Links open outside the app and tell the target nothing about where they came from.
            sanitisedNode.setAttribute('target', '_blank');
            sanitisedNode.setAttribute('rel', 'noopener noreferrer nofollow');
        }
        if (tagName === 'IMG') {
            var imageSource = sanitisedNode.getAttribute('src') || '';
            if (imageSource.indexOf(attachmentUrlPrefix) !== 0) {
                // An image without a source loads nothing.
                sanitisedNode.removeAttribute('src');
                sanitisedNode.removeAttribute('srcset');
                return;
            }
            sanitisedNode.setAttribute('loading', 'lazy');
        }
        // Task-list checkboxes are the only inputs marked produces; anything else becomes an
        // inert checkbox (raw HTML is escaped in layer 1, so this is belt and braces).
        if (tagName === 'INPUT' && sanitisedNode.getAttribute('type') !== 'checkbox') {
            sanitisedNode.setAttribute('type', 'checkbox');
            sanitisedNode.setAttribute('disabled', '');
        }
    });

    var sanitiserSettings = {
        RETURN_DOM_FRAGMENT: true,
        FORBID_TAGS: ['style', 'form', 'button', 'select', 'textarea', 'iframe', 'object', 'embed', 'svg', 'math'],
        FORBID_ATTR: ['style'],
    };

    /**
     * Finds the task-list lines in Markdown source, skipping fenced code blocks (where "- [ ]"
     * is just text and marked draws no checkbox).
     *
     * @returns {number[]} Line numbers of the task lines, in document order.
     */
    function findTaskLines(sourceLines) {
        var taskLineNumbers = [];
        var openFence = null;
        sourceLines.forEach(function (sourceLine, lineNumber) {
            var fenceMatch = /^[ \t]*(`{3,}|~{3,})/.exec(sourceLine);
            if (fenceMatch) {
                if (openFence === null) {
                    openFence = fenceMatch[1].charAt(0);
                } else if (fenceMatch[1].charAt(0) === openFence) {
                    openFence = null;
                }
                return;
            }
            if (openFence === null && taskLinePattern.test(sourceLine)) {
                taskLineNumbers.push(lineNumber);
            }
        });
        return taskLineNumbers;
    }

    /**
     * Returns the Markdown with the n-th task box ticked or cleared, or null when the source
     * does not have that box (the caller then refuses to save rather than guess).
     */
    function setTaskChecked(markdownSource, taskIndex, isChecked) {
        var sourceLines = markdownSource.split('\n');
        var taskLineNumbers = findTaskLines(sourceLines);
        if (taskIndex < 0 || taskIndex >= taskLineNumbers.length) {
            return null;
        }
        var lineNumber = taskLineNumbers[taskIndex];
        sourceLines[lineNumber] = sourceLines[lineNumber].replace(taskLinePattern, function (wholeMatch, beforeBox, boxState, afterBox) {
            return beforeBox + (isChecked ? 'x' : ' ') + afterBox;
        });
        return sourceLines.join('\n');
    }

    /**
     * Renders Markdown into a container, replacing what was there.
     *
     * @param {string} markdownSource The note's Markdown.
     * @param {HTMLElement} container Where the rendered note goes.
     * @param {function(number, boolean)} [onTaskToggle] When given, task checkboxes become
     *        clickable and report (task index, new state). Without it they stay read-only.
     */
    function render(markdownSource, container, onTaskToggle) {
        var renderedHtml = markdownParser.parse(markdownSource || '');
        var safeFragment = window.DOMPurify.sanitize(renderedHtml, sanitiserSettings);
        container.replaceChildren(safeFragment);

        // An image that no longer exists (removed, or an old version's image) shows a short
        // note instead of the browser's broken-image icon.
        Array.prototype.forEach.call(container.querySelectorAll('img.muninn-note-image'), function (noteImage) {
            noteImage.addEventListener('error', function () {
                var missingLabel = (noteImage.getAttribute('alt') || 'Image') + ' (image removed)';
                noteImage.replaceWith(MuninnApi.createElement('span', 'muninn-missing-image', missingLabel));
            });
        });

        var taskCheckboxes = container.querySelectorAll('input[type="checkbox"]');
        // Only allow ticking when every checkbox maps to exactly one source line.
        var canToggle = typeof onTaskToggle === 'function'
            && taskCheckboxes.length === findTaskLines((markdownSource || '').split('\n')).length;

        Array.prototype.forEach.call(taskCheckboxes, function (taskCheckbox, taskIndex) {
            var taskItem = taskCheckbox.closest('li');
            if (taskItem) {
                taskItem.classList.add('muninn-task-item');
                taskItem.parentElement.classList.add('muninn-task-list');
            }
            var taskLabel = taskItem ? taskItem.textContent.trim() : 'Task';
            taskCheckbox.classList.add('form-check-input');
            taskCheckbox.setAttribute('aria-label', taskLabel || 'Task');
            if (!canToggle) {
                taskCheckbox.disabled = true;
                return;
            }
            taskCheckbox.disabled = false;
            taskCheckbox.addEventListener('change', function () {
                onTaskToggle(taskIndex, taskCheckbox.checked);
            });
        });
    }

    window.MuninnMarkdown = {
        render: render,
        setTaskChecked: setTaskChecked,
        attachmentUrl: attachmentUrl,
    };
})();
