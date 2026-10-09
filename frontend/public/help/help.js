/*
 * Muninn help pages: switches every page between English and Swedish.
 *
 * Each page holds both languages. Text blocks are marked with lang="en" or lang="sv", and
 * help.css hides the blocks of the language that is not chosen. This script only decides which
 * language is chosen and sets data-help-language on the <html> element.
 *
 * The language is chosen in this order:
 *   1. ?lang=sv or ?lang=en in the address (so a link can open a given language),
 *   2. the visitor's earlier choice, remembered in this browser,
 *   3. the browser's own language list (Swedish if it prefers Swedish),
 *   4. English.
 *
 * The script is loaded in <head> without "defer", so the language is set before the page is
 * drawn and the wrong language never flashes. Without JavaScript the page shows English.
 */
(function () {
    'use strict';

    var supportedLanguages = ['en', 'sv'];
    var defaultLanguage = 'en';
    var storageKey = 'muninn-help-language';
    var rootElement = document.documentElement;

    /** True when the value is one of the languages these pages are written in. */
    function isSupportedLanguage(languageCode) {
        return supportedLanguages.indexOf(languageCode) !== -1;
    }

    /** The language asked for in the address (?lang=sv), or null. */
    function readLanguageFromAddress() {
        var addressMatch = /[?&]lang=([a-z]{2})\b/i.exec(window.location.search);
        if (addressMatch && isSupportedLanguage(addressMatch[1].toLowerCase())) {
            return addressMatch[1].toLowerCase();
        }
        return null;
    }

    /** The visitor's earlier choice. Storage can be blocked (private mode), so failures are ignored. */
    function readStoredLanguage() {
        try {
            var storedLanguage = window.localStorage.getItem(storageKey);
            return isSupportedLanguage(storedLanguage) ? storedLanguage : null;
        } catch (storageError) {
            return null;
        }
    }

    /** Remembers the visitor's choice for the next visit, when the browser allows it. */
    function storeLanguage(languageCode) {
        try {
            window.localStorage.setItem(storageKey, languageCode);
        } catch (storageError) {
            // Nothing to do: the choice then lasts only for this page.
        }
    }

    /** The first supported language in the browser's preference list, or null. */
    function readBrowserLanguage() {
        var preferredLanguages = navigator.languages && navigator.languages.length
            ? navigator.languages
            : [navigator.language || ''];
        for (var languageIndex = 0; languageIndex < preferredLanguages.length; languageIndex++) {
            // "sv-SE" and "sv" both count as Swedish; only the first two letters matter.
            var baseLanguage = String(preferredLanguages[languageIndex]).slice(0, 2).toLowerCase();
            if (isSupportedLanguage(baseLanguage)) {
                return baseLanguage;
            }
        }
        return null;
    }

    /** Picks the language to show when the page opens. */
    function chooseStartLanguage() {
        return readLanguageFromAddress() || readStoredLanguage() || readBrowserLanguage() || defaultLanguage;
    }

    /**
     * Shows the page in the given language: sets the attributes the CSS and screen readers use,
     * the browser tab title, and which switch button is pressed.
     */
    function applyLanguage(languageCode) {
        rootElement.setAttribute('data-help-language', languageCode);
        rootElement.setAttribute('lang', languageCode);

        // Each page carries its title in both languages on <html data-title-en data-title-sv>.
        var translatedTitle = rootElement.getAttribute('data-title-' + languageCode);
        if (translatedTitle) {
            document.title = translatedTitle;
        }

        var switchButtons = document.querySelectorAll('[data-set-language]');
        for (var buttonIndex = 0; buttonIndex < switchButtons.length; buttonIndex++) {
            var switchButton = switchButtons[buttonIndex];
            var isChosen = switchButton.getAttribute('data-set-language') === languageCode;
            switchButton.setAttribute('aria-pressed', isChosen ? 'true' : 'false');
        }
    }

    // Set the language at once, before the page is drawn. Marking that the script runs lets the
    // CSS show the language switch, which would do nothing without JavaScript.
    rootElement.setAttribute('data-help-script', 'on');
    applyLanguage(chooseStartLanguage());

    // Once the page exists, connect the switch buttons and update their pressed state.
    document.addEventListener('DOMContentLoaded', function () {
        var switchButtons = document.querySelectorAll('[data-set-language]');
        for (var buttonIndex = 0; buttonIndex < switchButtons.length; buttonIndex++) {
            switchButtons[buttonIndex].addEventListener('click', function (clickEvent) {
                var chosenLanguage = clickEvent.currentTarget.getAttribute('data-set-language');
                if (isSupportedLanguage(chosenLanguage)) {
                    storeLanguage(chosenLanguage);
                    applyLanguage(chosenLanguage);
                }
            });
        }
        applyLanguage(rootElement.getAttribute('data-help-language') || defaultLanguage);
    });
})();
