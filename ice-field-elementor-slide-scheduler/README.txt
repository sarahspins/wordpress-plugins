=== Ice & Field Elementor Slide Scheduler ===
Contributors: iceandfield
Tags: elementor, slides, scheduling
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Adds optional start and end display times to each slide in Elementor Pro's Slides widget.

== Description ==

Ice & Field Elementor Slide Scheduler adds two fields to every item in Elementor Pro's Slides widget:

* Start Display On — hides the slide until the selected date and time.
* End Display On — hides the slide at and after the selected date and time.

Both fields are optional and use the timezone selected under WordPress Settings > General. Scheduled and expired slides remain available in the Elementor editor so they can be revised, rescheduled, or reused.

This plugin does not delete slide content.

== Installation ==

1. Install and activate Elementor and Elementor Pro.
2. Upload and activate this plugin.
3. Edit an Elementor Pro Slides widget.
4. Expand a slide and set either scheduling field as needed.

== Notes ==

The schedule is evaluated whenever WordPress renders the widget. A full-page cache or CDN may continue serving an older rendered page until that cache expires or is purged. Configure the page cache duration accordingly for time-sensitive changes.

== Changelog ==

= 1.0.0 =
* Added optional per-slide start and end display times.
* Kept scheduled and expired slides editable in Elementor.
