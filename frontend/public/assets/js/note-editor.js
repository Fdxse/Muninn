/*
 * Note editor: a plain textarea with a small Markdown toolbar, a Write/Preview switch and image
 * upload by button, paste or drag and drop (decision D035: mobile-friendly, no rich-text editor).
 *
 * The page script creates it with MuninnNoteEditor.attach({...}) and supplies uploadImage(file),
 * which stores the image through the API and resolves with the Markdown to insert. Pasted and
 * picked images go through that same function, so they get the same server-side validation.
 */
(function () {
    'use strict';

    /** Largest image the editor sends; the API enforces its own (configurable) limit as well. */
    var maximumImageBytes = 10 * 1000 * 1000;

    /** Image types the API accepts. Keeping this list makes iOS convert HEIC photos to JPEG. */
    var acceptedImageTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * Replaces the textarea's selection with text and selects the given part of the new text,
     * then reports an input event so the page notices the change.
     */
    function replaceSelection(textArea, replacementText, selectFromOffset, selectToOffset) {
        var selectionStart = textArea.selectionStart;
        var selectionEnd = textArea.selectionEnd;
        textArea.focus();
        textArea.setRangeText(replacementText, selectionStart, selectionEnd, 'end');
        if (typeof selectFromOffset === 'number') {
            textArea.setSelectionRange(selectionStart + selectFromOffset, selectionStart + (selectToOffset === undefined ? selectFromOffset : selectToOffset));
        }
        textArea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /** Wraps the selection in markers, e.g. ** for bold; with no selection inserts a placeholder. */
    function wrapSelection(textArea, marker, placeholderText) {
        var selectedText = textArea.value.slice(textArea.selectionStart, textArea.selectionEnd) || placeholderText;
        replaceSelection(textArea, marker + selectedText + marker, marker.length, marker.length + selectedText.length);
    }

    /** Puts a prefix such as "- [ ] " in front of every line the selection touches. */
    function prefixSelectedLines(textArea, linePrefix) {
        var lineStart = textArea.value.lastIndexOf('\n', textArea.selectionStart - 1) + 1;
        var lineEndIndex = textArea.value.indexOf('\n', textArea.selectionEnd);
        var lineEnd = lineEndIndex === -1 ? textArea.value.length : lineEndIndex;
        textArea.setSelectionRange(lineStart, lineEnd);
        var prefixedLines = textArea.value.slice(lineStart, lineEnd).split('\n').map(function (lineText) {
            return linePrefix + lineText;
        }).join('\n');
        replaceSelection(textArea, prefixedLines, prefixedLines.length);
    }

    /** Inserts a block (code block, image) on lines of its own at the cursor. */
    function insertBlock(textArea, blockText, selectFromOffset, selectToOffset) {
        var textBefore = textArea.value.slice(0, textArea.selectionStart);
        var leadingBreak = textBefore === '' || /\n$/.test(textBefore) ? '' : '\n';
        var fullText = leadingBreak + blockText + '\n';
        replaceSelection(
            textArea,
            fullText,
            typeof selectFromOffset === 'number' ? leadingBreak.length + selectFromOffset : fullText.length,
            typeof selectToOffset === 'number' ? leadingBreak.length + selectToOffset : undefined
        );
    }

    /** The toolbar actions, keyed by the data-editor-action attribute of each button. */
    var toolbarActions = {
        bold: function (textArea) { wrapSelection(textArea, '**', 'bold text'); },
        italic: function (textArea) { wrapSelection(textArea, '_', 'italic text'); },
        heading: function (textArea) { prefixSelectedLines(textArea, '## '); },
        bullets: function (textArea) { prefixSelectedLines(textArea, '- '); },
        checklist: function (textArea) { prefixSelectedLines(textArea, '- [ ] '); },
        code: function (textArea) {
            var selectedText = textArea.value.slice(textArea.selectionStart, textArea.selectionEnd);
            if (selectedText.indexOf('\n') === -1 && selectedText !== '') {
                wrapSelection(textArea, '`', 'code');
                return;
            }
            var codeText = selectedText || 'code';
            insertBlock(textArea, '```\n' + codeText + '\n```', 4, 4 + codeText.length);
        },
        link: function (textArea) {
            var linkText = textArea.value.slice(textArea.selectionStart, textArea.selectionEnd) || 'link text';
            var linkMarkdown = '[' + linkText + '](https://)';
            // Select the URL part so the user can type or paste it straight away.
            replaceSelection(textArea, linkMarkdown, linkText.length + 3, linkMarkdown.length - 1);
        },
    };

    /**
     * Connects the editor controls.
     *
     * @param {object} settings
     * @param {HTMLTextAreaElement} settings.textArea
     * @param {HTMLElement} settings.toolbar Contains buttons with data-editor-action attributes.
     * @param {HTMLInputElement} settings.imageInput A file input for picking images.
     * @param {HTMLElement} settings.writeTab, settings.previewTab Buttons switching the view.
     * @param {HTMLElement} settings.previewPane Where the preview is rendered.
     * @param {HTMLElement} settings.statusLine A live region for upload progress and errors.
     * @param {function(File): Promise<string>} settings.uploadImage Stores an image, resolves with Markdown.
     */
    function attach(settings) {
        var textArea = settings.textArea;

        settings.toolbar.addEventListener('click', function (clickEvent) {
            var actionButton = clickEvent.target.closest('[data-editor-action]');
            if (!actionButton) {
                return;
            }
            var actionName = actionButton.getAttribute('data-editor-action');
            if (actionName === 'image') {
                settings.imageInput.click();
                return;
            }
            if (toolbarActions[actionName]) {
                showWrite();
                toolbarActions[actionName](textArea);
            }
        });

        /** Shows a short status message (screen readers announce it via aria-live). */
        function showStatus(message, isError) {
            settings.statusLine.textContent = message;
            settings.statusLine.classList.toggle('text-danger', Boolean(isError));
        }

        /** Uploads image files one after another and inserts each at the cursor. */
        async function uploadImages(imageFiles) {
            for (var fileIndex = 0; fileIndex < imageFiles.length; fileIndex++) {
                var imageFile = imageFiles[fileIndex];
                if (acceptedImageTypes.indexOf(imageFile.type) === -1) {
                    showStatus('Only PNG, JPEG, GIF and WebP images can be added.', true);
                    continue;
                }
                if (imageFile.size > maximumImageBytes) {
                    showStatus('That image is larger than 10 MB.', true);
                    continue;
                }
                showStatus('Uploading image…', false);
                try {
                    var imageMarkdown = await settings.uploadImage(imageFile);
                    showWrite();
                    insertBlock(textArea, imageMarkdown);
                    showStatus('Image added.', false);
                } catch (uploadError) {
                    showStatus(uploadError.message || 'The image could not be uploaded.', true);
                }
            }
        }

        /** Image files in a clipboard or drop, if any. */
        function imageFilesIn(dataTransfer) {
            if (!dataTransfer || !dataTransfer.files) {
                return [];
            }
            return Array.prototype.filter.call(dataTransfer.files, function (transferredFile) {
                return transferredFile.type.indexOf('image/') === 0;
            });
        }

        settings.imageInput.setAttribute('accept', acceptedImageTypes.join(','));
        settings.imageInput.addEventListener('change', function () {
            var pickedFiles = Array.prototype.slice.call(settings.imageInput.files || []);
            settings.imageInput.value = '';
            uploadImages(pickedFiles);
        });

        // Clipboard image paste (e.g. a screenshot). Pasted text keeps the browser's normal behaviour.
        textArea.addEventListener('paste', function (pasteEvent) {
            var pastedImages = imageFilesIn(pasteEvent.clipboardData);
            if (pastedImages.length > 0) {
                pasteEvent.preventDefault();
                uploadImages(pastedImages);
            }
        });

        textArea.addEventListener('dragover', function (dragEvent) {
            if (dragEvent.dataTransfer && Array.prototype.indexOf.call(dragEvent.dataTransfer.types, 'Files') !== -1) {
                dragEvent.preventDefault();
            }
        });
        textArea.addEventListener('drop', function (dropEvent) {
            var droppedImages = imageFilesIn(dropEvent.dataTransfer);
            if (droppedImages.length > 0) {
                dropEvent.preventDefault();
                uploadImages(droppedImages);
            }
        });

        /** Shows the textarea. */
        function showWrite() {
            settings.writeTab.classList.add('active');
            settings.writeTab.setAttribute('aria-selected', 'true');
            settings.previewTab.classList.remove('active');
            settings.previewTab.setAttribute('aria-selected', 'false');
            textArea.classList.remove('d-none');
            settings.previewPane.classList.add('d-none');
        }

        /** Shows the rendered preview of what is in the textarea. */
        function showPreview() {
            MuninnMarkdown.render(textArea.value, settings.previewPane);
            if (textArea.value.trim() === '') {
                settings.previewPane.replaceChildren(MuninnApi.createElement('p', 'text-muted-brand', 'Nothing to preview yet.'));
            }
            settings.previewTab.classList.add('active');
            settings.previewTab.setAttribute('aria-selected', 'true');
            settings.writeTab.classList.remove('active');
            settings.writeTab.setAttribute('aria-selected', 'false');
            textArea.classList.add('d-none');
            settings.previewPane.classList.remove('d-none');
        }

        settings.writeTab.addEventListener('click', function () {
            showWrite();
            textArea.focus();
        });
        settings.previewTab.addEventListener('click', showPreview);

        return { showWrite: showWrite, showStatus: showStatus };
    }

    window.MuninnNoteEditor = { attach: attach };
})();
