(function ($) {
    'use strict';

    var settings = window.MediaFoldersLibrary || {};
    var pendingDrag = null;
    var activeDrag = null;
    var dragThreshold = 6;

    function showNotice(message, isError) {
        var notice = document.createElement('div');
        notice.className = 'mf-drag-notice ' + (isError ? 'is-error' : 'is-success');
        notice.textContent = message;
        document.body.appendChild(notice);

        window.setTimeout(function () {
            notice.classList.add('is-hidden');
            window.setTimeout(function () {
                notice.remove();
            }, 250);
        }, 4500);
    }

    function getFolderUnderPointer(clientX, clientY) {
        var element = document.elementFromPoint(clientX, clientY);
        if (!element) {
            return null;
        }
        return element.closest('.mf-media-folder-drop-target');
    }

    function clearDropTargetHighlight() {
        document.querySelectorAll('.mf-media-folder-drop-target.is-drag-over').forEach(function (folder) {
            folder.classList.remove('is-drag-over');
        });
    }

    function finishDrag(folderElement) {
        if (!activeDrag) {
            return;
        }

        var attachmentId = activeDrag.attachmentId;
        if (activeDrag.ghost) {
            activeDrag.ghost.remove();
        }
        activeDrag.attachment.classList.remove('is-being-dragged');
        document.body.style.userSelect = '';
        clearDropTargetHighlight();

        if (folderElement) {
            assignAttachment(attachmentId, folderElement);
        }
        activeDrag = null;
    }

    function assignAttachment(attachmentId, folderElement) {
        var folderId = folderElement.getAttribute('data-folder-id');
        folderElement.classList.add('is-assigning');

        $.post(settings.ajaxUrl, {
            action: 'mf_assign_attachment',
            nonce: settings.nonce,
            attachment_id: attachmentId,
            folder_id: folderId
        })
            .done(function (response) {
                if (response && response.success) {
                    if (response.data && typeof response.data.count !== 'undefined') {
                        var countElement = folderElement.querySelector('.mf-folder-count');
                        if (countElement) {
                            countElement.textContent = response.data.count;
                        }
                    }
                    showNotice(response.data.message || settings.successText, false);
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 450);
                    return;
                }
                showNotice((response && response.data && response.data.message) || settings.errorText, true);
            })
            .fail(function (xhr) {
                var data = xhr && xhr.responseJSON && xhr.responseJSON.data;
                showNotice((data && data.message) || settings.errorText, true);
            })
            .always(function () {
                folderElement.classList.remove('is-assigning');
            });
    }

    function startPointerDrag(event) {
        if (!pendingDrag || activeDrag) {
            return;
        }

        var distanceX = Math.abs(event.clientX - pendingDrag.startX);
        var distanceY = Math.abs(event.clientY - pendingDrag.startY);
        if (distanceX < dragThreshold && distanceY < dragThreshold) {
            return;
        }

        activeDrag = {
            attachmentId: pendingDrag.attachmentId,
            attachment: pendingDrag.attachment,
            ghost: pendingDrag.attachment.cloneNode(true)
        };
        pendingDrag = null;
        event.preventDefault();

        activeDrag.attachment.classList.add('is-being-dragged');
        activeDrag.ghost.className = 'mf-drag-ghost';
        activeDrag.ghost.setAttribute('aria-hidden', 'true');
        activeDrag.ghost.style.width = activeDrag.attachment.offsetWidth + 'px';
        document.body.appendChild(activeDrag.ghost);
        document.body.style.userSelect = 'none';
        movePointerDrag(event);
    }

    function movePointerDrag(event) {
        if (!activeDrag) {
            return;
        }

        event.preventDefault();
        activeDrag.ghost.style.left = (event.clientX + 12) + 'px';
        activeDrag.ghost.style.top = (event.clientY + 12) + 'px';

        clearDropTargetHighlight();
        var folder = getFolderUnderPointer(event.clientX, event.clientY);
        if (folder) {
            folder.classList.add('is-drag-over');
        }
    }

    function endPointerDrag(event) {
        if (pendingDrag) {
            pendingDrag = null;
        }
        if (!activeDrag) {
            return;
        }

        var folder = getFolderUnderPointer(event.clientX, event.clientY);
        finishDrag(folder);
    }

    function makeAttachmentDraggable(attachment) {
        if (attachment.getAttribute('data-mf-draggable') === 'true') {
            return;
        }

        var attachmentId = attachment.getAttribute('data-id');
        if (!attachmentId) {
            return;
        }

        attachment.setAttribute('data-mf-draggable', 'true');
        attachment.addEventListener('mousedown', function (event) {
            if (event.button !== 0 || event.target.closest('button, a, input, textarea, select')) {
                return;
            }

            pendingDrag = {
                attachment: attachment,
                attachmentId: attachmentId,
                startX: event.clientX,
                startY: event.clientY
            };
        });
    }

    function createFolderElement(folder) {
        var folderElement = document.createElement('div');
        folderElement.className = 'mf-media-folder-drop-target';
        folderElement.setAttribute('data-folder-id', folder.id);
        folderElement.setAttribute('role', 'button');
        folderElement.setAttribute('tabindex', '0');
        folderElement.innerHTML = '<span class="mf-folder-icon" aria-hidden="true">▰</span>' +
            '<span class="mf-folder-name"></span>' +
            '<span class="mf-folder-count"></span>';
        folderElement.querySelector('.mf-folder-name').textContent = folder.name;
        folderElement.querySelector('.mf-folder-count').textContent = folder.count;
        return folderElement;
    }

    function createDock() {
        if (document.querySelector('#mf-media-folder-dock')) {
            return;
        }

        var attachmentsBrowser = document.querySelector('.attachments-browser');
        if (!attachmentsBrowser) {
            return;
        }

        var dock = document.createElement('aside');
        dock.id = 'mf-media-folder-dock';
        dock.innerHTML = '<div class="mf-media-folder-dock-heading"><strong>Media folders</strong><a href="' + settings.foldersUrl + '">Manage</a></div><p class="mf-media-folder-help">Drag a file onto a folder.</p>';

        var folderList = document.createElement('div');
        folderList.className = 'mf-media-folder-list';
        if (!settings.folders || !settings.folders.length) {
            folderList.innerHTML = '<p class="mf-media-folder-empty">Create a folder first.</p>';
        } else {
            settings.folders.forEach(function (folder) {
                folderList.appendChild(createFolderElement(folder));
            });
        }

        dock.appendChild(folderList);
        attachmentsBrowser.insertBefore(dock, attachmentsBrowser.firstChild);
    }

    function decorateAttachments() {
        document.querySelectorAll('.attachments .attachment').forEach(makeAttachmentDraggable);
    }

    function initialize() {
        createDock();
        decorateAttachments();

        document.addEventListener('mousemove', startPointerDrag);
        document.addEventListener('mousemove', movePointerDrag);
        document.addEventListener('mouseup', endPointerDrag);

        var observer = new MutationObserver(function () {
            createDock();
            decorateAttachments();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    $(initialize);
}(jQuery));
