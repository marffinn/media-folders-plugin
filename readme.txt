=== Media Folders ===
Contributors: manus
Tags: media library, folders, attachments, organization
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create folders for WordPress Media Library attachments and catalogue files more effectively.

== Description ==

Media Folders creates real directories for your media. Assigning a file to a folder physically moves it (including all generated image sizes), so the public URL contains the folder name, e.g. https://example.com/wp-content/uploads/contracts/file.pdf or, if you choose the site-root option, https://example.com/contracts/file.pdf.

Administrators can:

* Create and delete media folders.
* Select media files directly on Media > Folders and assign them to a destination folder.
* See folders in the normal Media Library grid and drag media tiles onto a folder drop target.
* Assign one folder to an attachment from the attachment edit screen.
* Filter the Media Library by folder.
* See a Folder column in the Media Library list view.
* Move selected media items into a folder using bulk actions.
* Remove selected media items from their folders using a bulk action.

Deleting a folder does not delete any media files. The files remain in WordPress and simply become unassigned.

== Installation ==

1. Upload the `media-folders` folder to `wp-content/plugins/`.
2. Activate Media Folders from Plugins > Installed Plugins.
3. Open Media > Folders and create your first folder.
4. Assign files from the attachment edit screen or use Media Library bulk actions.

== Requirements ==

* WordPress 5.8 or newer.
* PHP 7.4 or newer.
* Administrator capability (`manage_options`) for folder management and assignment.

== Notes ==

* Old URLs are rewritten inside post content and 301-redirected (when the request reaches WordPress). URLs stored elsewhere (page builders, serialized options, external sites) are not rewritten.
* Folder names become directory names (slug). Folders cannot be renamed after creation.
* A file is never overwritten: a move is refused if the destination already holds a file with the same name.
* The folder location can only be changed while no files are assigned to folders.
* Site-root mode needs the web server to be able to write to the WordPress root directory.

== Changelog ==

= 2.0.0 =
* Folders are now real directories; assigning moves files on disk and updates paths and URLs.
* Optional site-root location, redirects from old URLs, safe rollback on failed moves.
* Deleting a folder moves its files back to the default uploads location.

= 1.3.1 =
* Fixed a fatal error on the Media Library caused by an instance method being called from a static callback.

= 1.0.0 =
* Initial release.
