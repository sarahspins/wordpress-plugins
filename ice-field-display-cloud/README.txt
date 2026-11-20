=== Ice & Field Display Cloud ===
Contributors: iceandfield
Tags: digital signage, cloud backup, displays
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later

Authenticated WordPress backup storage for Ice & Field Pi display content.

== Description ==

This standalone companion plugin receives checksummed media objects and an atomic content manifest from the main Ice & Field display controller. It appears under Displays > Pi Cloud Storage when Ice & Field Rink Displays is active. Otherwise it provides a small Display Cloud top-level menu.

Publishing uses WordPress REST API application-password authentication. The current manifest and stored media are publicly readable so display nodes can recover content without storing WordPress credentials.

Version 0.2.0 also provides an authenticated Video for Screens slideshow feed, including each slide's optional begin and stop times. The master display controller polls that feed, downloads changed images into its local media library, and distributes the resulting local playlist to playback nodes. Continued playback and scheduled eligibility never depend on WordPress availability.

The companion master-slideshow feed reads display 01's most recently published manifest. The Video for Screens page can preview that active master playlist and explicitly import it into WordPress for editing without silently overwriting pending WordPress changes.

Cloud objects are limited to MP4, WebM, PNG, JPEG, and WebP. SVG is deliberately excluded from the public same-origin storage folder.

This plugin is intentionally outside the five-component Ice & Field updater while the Pi fleet synchronization workflow is being tested.

== Installation ==

1. Upload and activate the plugin on the backup WordPress website.
2. Open Displays > Pi Cloud Storage.
3. Create a dedicated WordPress application password for a user with upload-files and edit-pages capabilities.
4. Enter the site address, username, and application password on the main display-controller Pi when its cloud publishing settings are available.

== Changelog ==

= 0.2.1 =
* Carry optional slide begin times through the WordPress-to-master and master-to-WordPress feeds.

= 0.2.0 =
* Add an authenticated Video for Screens slideshow feed for the master Pi controller.
* Expose the master controller's published slideshow for preview and explicit import in WordPress.
* Show the feed address on the Pi Cloud Storage status page.

= 0.1.1 =
* Load WordPress's file helper explicitly before preparing an atomic manifest during REST requests.

= 0.1.0 =
* Initial authenticated object upload, atomic manifest publication, public manifest retrieval, storage status, and Displays submenu integration.
