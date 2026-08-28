=== Ice & Field Programming ===
Contributors: iceandfield
Stable tag: 1.9.2.2
Requires at least: 6.4
Requires PHP: 7.4
Requires Plugins: ice-field-dash-connector

Manage seasons, program groups, levels, classes, leagues, camps, clinics, and public registration displays for Ice & Field.

== Description ==

Ice & Field Programming is the public program and registration module for the main Ice & Field website.

Version 1.9.2.2 adds a per-Season “Pin this Season to the top” display override. Pinned Seasons appear before automatically date-sorted Seasons in customer-facing catalogs and offering lists, while all unpinned Seasons retain their existing chronological order.

Version 1.9.2.1 separates lightweight Season discovery from hierarchy comparison work for compatibility with hosts that enforce short gateway timeouts. Opening or refreshing Season Discovery no longer rebuilds every imported Season. Use Check Changes on one imported Season to refresh only that comparison; protected previews request fresh Teams for the selected Season while reusing cached shared Dash indexes.

Version 1.9.2 adds opt-in daily synchronization for Seasons that have already been imported from Dash. Each imported Season has its own Keep up to date automatically checkbox. While its Dash registration window is open, Programming imports newly added eligible class offerings and refreshes protected Dash-controlled facts once daily. Name and description synchronization are separate opt-ins and remain off by default; descriptions retain the existing protection for locally edited WordPress copy. Closed, not-yet-open, and undated registration windows are skipped safely, and every run is recorded in the Programming Activity Log. On the public catalog, a registration-closed Season retains its chronological position while classes are still occurring; an older Season disappears automatically once every dated class occurrence has passed.

It is intentionally separate from:

* Ice & Field Productions, which manages skating shows.
* Ice & Field Rink Displays, which manages television schedules and participant screens.
* Ice & Field Dash Connector, which owns DaySmart/Dash credentials and API access.

Version 1.9.1.10 remains local-first and uses a website-managed Program Group layer in the hierarchy discovered in Dash: Season > Program Group > Level > Program. A Level can belong to multiple Groups, allowing the same Level and its classes to appear in places such as both Snowplow Sam and Hockey. Levels without a Program Group appear directly beneath all defined Group accordions instead of being nested inside an extra Other Programs section. Program Categories can be assigned to either a complete Level or an individual Program; shortcode category filters combine direct class assignments with categories inherited from the linked Level. Built-in Homeschool and Adaptive categories support special website tabs. Existing and future class names beginning with HS are categorized as Homeschool automatically, while an Adaptive Level receives the Adaptive category once for all of its classes. Existing manual category assignments remain intact. Multiple Seasons can be Current at the same time for different programming areas. Seasons remain chronological by start date and then end date; when both dates match, an editable Display Order places lower numbers first. Completed Seasons remain grouped at the bottom. Season descriptions appear at the top of expanded Season contents and can be enabled or disabled globally in Display Settings. Expanded Seasons use a compact header-to-content seam whether they begin with a description, Program Group, Level, or class list. An upcoming Dash Season can be imported before it has any Levels or classes, either as a draft or published immediately. Published empty Upcoming Seasons appear as compact Coming Soon rows with their Season and registration dates, including in matching Sport- and Format-filtered catalogs. Sport and Format shortcode values match both current slugs and normalized visible term names, retaining compatibility with older terms such as Hockey whose saved slug is `h`. Season-only imports receive editable Sport and Format classifications from available Dash fields or conservative Season-name matching, while existing imports use the same fallback without requiring re-import. Once Programs are imported and published, the Season automatically uses the normal collapsed Session, Program Group, and Level accordions followed by compact class lists sorted Monday through Sunday and then by starting time. Levels inside each Program Group are sorted from the youngest starting age to the oldest. Dash year-and-month boundaries are converted safely to whole-year labels, correcting imported starting ages without replacing manually customized age ranges. The Programs admin list includes direct and Level-inherited Program Categories for easier catalog maintenance. The public catalog has no outer frame or inset padding. Season, Program Group, Level, and class backgrounds, borders, widths, and corner treatments can be adjusted independently, with the Season color filling its complete expanded area and the Program Group color remaining consistent across its heading and expanded panel. By default, all Current, Upcoming, and Completed Seasons are included, with closed Seasons grouped at the bottom as compact, non-expandable rows. During a dated registration window, a Season is treated as Current automatically—even if an older release previously changed its saved flag. After registration closes, it is treated as Completed, the catalog shows a muted Registration Closed status, and registration links are removed. The Seasons screen offers one-click prompts to make either automatic lifecycle change permanent. Dash imports can remain draft-first or explicitly publish imported records while preserving any manually customized presentation. The empty-Season catalog lookup uses defensive ID-only queries for compatibility with larger WordPress installations.

A Dash League without any Team/class records can be previewed, selected, and imported as a standalone registrable Level without creating a placeholder Program. Once published, it appears in the normal public hierarchy and uses its broader League registration destination.

Dash League #86 is an intentional presentation exception: its Level and linked Programs follow the published Current Learn to Play Season instead of the unrelated Dash parent Season. It is never moved to an Upcoming Learn to Play Season because its registration link already exposes future drop-in dates. If it has never been imported and no Current Learn to Play Season exists, the import pauses with a clear message instead of placing it under the wrong Season.

The Season Discovery Inbox loads automatically when its admin screen opens, lists every non-excluded Current and Upcoming Dash Season directly, groups New Seasons first, Needs Re-sync second, and Imported Seasons last, and provides Preview / Import, Sync, or Review Changes actions. A prominent prompt calls attention to newly discovered Seasons and links directly to the New Seasons group. A secondary All Seasons view filters Current, Upcoming, Completed, Imported, New, Needs Re-sync, and Excluded records. Never Import exclusions are reversible and stored by Dash Season ID. Checkboxes, Select All/Deselect All controls, and Exclude Selected make it possible to exclude several visible Seasons together. Automatic loading uses the Connector cache for speed; Refresh Discovery explicitly requests fresh source data. Discovery compares imported Current and Upcoming Seasons with compact snapshots from their last successful sync, including Season facts and structural Level/class changes while ignoring routine enrollment totals. The first 1.8 refresh establishes a baseline for older imports that predate snapshots; later refreshes compare against that baseline or the latest successful sync.

The Monitoring screen stores broader Season-family discovery rules. Daily synchronization is enabled independently on each imported Season and runs at approximately 4:15 a.m. in the WordPress site timezone. It checks Dash only for opted-in Seasons, synchronizes only during an open Dash registration window, and uses an overlap lock. The Activity Log records monitoring-setting changes, automatic and manual sync results, manual discovery refreshes, Never Import exclusions/restorations, warnings, and failures. It stores no raw Dash payloads or credentials and is capped at 500 entries.

== Installation ==

1. Install and configure Ice & Field Dash Connector v1.3.0 or newer.
2. Upload the complete ice-field-programming-v1.9.1.10 ZIP in Plugins > Add Plugin > Upload Plugin.
3. Activate Ice & Field Programming.
4. Open Programming in the WordPress admin menu.
5. Open Dash Sync, choose a Dash Season, and preview its Team classes and standalone registrable Leagues.
6. If the Season has no Teams or Leagues yet, import the Season by itself. Otherwise, select the Team-based Programs and/or standalone League-based Levels to import as drafts or publish immediately.
7. Open each Level and choose every Program Group where it should appear. Existing unassigned Levels remain visible directly below the defined Program Groups until categorized.
8. Review the imported Seasons, Levels, and Programs, assign Program Groups, and adjust any presentation details needed for the public catalog.

== Data Model ==

= Seasons =

A Season is the parent for its public registration offerings. Season fields include:

* Lifecycle: Draft, Upcoming, Current, Completed, or Archived
* Optional public description shown inside an expanded Season
* Start and end dates
* Default registration opening and closing dates
* Optional Dash Season ID
* Optional manual registration URL; otherwise the Dash Season destination is used
* Sport and Format classifications used by filtered public catalogs
* Display Order for Seasons sharing the same start and end dates
* A copyable Season-specific shortcode in the Season editor

Multiple Seasons can be Current at the same time, allowing separate active Seasons for Learn to Skate, hockey, camps, and other programming areas.

= Program Groups =

A Program Group is a reusable website-managed layer between Seasons and Levels. The plugin creates starter Groups for Basic Skills, Adult, Free Skate, Snowplow Sam, and Hockey.

Program Group fields include:

* Name and optional public description
* Display Order; lower numbers appear first
* Reuse across multiple Seasons and Levels

Manage Groups under Programming > Program Groups. Dash does not create, change, or remove Program Groups.

= Levels =

A Level belongs to one Season and can belong to one or more Program Groups. Dash imports create one Level for each parent League represented by selected Teams. A League with no Teams can instead be imported directly as a standalone Level when the Level itself is the signup destination.

Level fields include:

* Season
* One or more Program Groups
* Optional Program Categories inherited by every Program in the Level
* Age range
* Optional Dash League ID
* Optional manual registration URL; otherwise the Dash Level destination is used
* Public level-wide description
* Page Order for controlling public group order

Imported Levels preserve their local Program Groups, Program Categories, title, description, publication status, and ordering during later refreshes. A Level and its classes are repeated beneath every assigned Group. Unassigned Levels remain visible directly below all defined Program Groups.

= Programs =

A Program can represent a:

* Class
* League
* Camp
* Clinic
* Drop-In
* Other locally created Program Category

Program fields include:

* Season
* Level
* Sport
* Format
* One or more Program Categories assigned only to this Program
* Start and end dates
* Registration opening and closing dates
* Schedule
* Age range
* Fallback Level label for older or unlinked records
* Location
* Price
* Session length in weeks
* Registration link and button text
* Availability message
* Full description and compact-row excerpt

== Dash Registration Links ==

When a Dash Team allows online signup, its import automatically generates separate DaySmart destinations for the individual class and its parent Level. A standalone League import generates the same broader Level destination without requiring a Team.

* Class buttons use the Connector's configured Dash company and the Team ID.
* Level buttons use the company, Team facility ID, and parent League ID.
* Season buttons use the company, Program ID, facility ID, and Season ID.

Each class button therefore opens its exact Team registration page, while the Level shortcut remains the broader destination for customers who want to choose among class times. Registration URL fields remain editable. A manually entered URL is treated as a protected local override and is not replaced by later Dash refreshes.

== Registration Status ==

Programs can use Automatic status or a manual override.

Automatic status follows this order:

1. Before the registration opening date: Coming Soon
2. Between opening and closing: Registration Open
3. After the closing date: Registration Closed
4. If a Program has no own registration dates, its Season defaults are used.
5. If no dates are available, the safe default is Coming Soon.

Manual choices are:

* Draft / hidden
* Coming Soon
* Registration Open
* Registration Closed
* Archived / hidden

WordPress publication status remains separate. A Program must be published and have a public registration state before a shortcode can display it.

== Shortcodes ==

The primary shortcode is:

[if_programming]

[if_programs] is provided as a shorter alias.

Supported attributes:

* sport: one or more Sport slugs
* format: one or more Format slugs
* category: one or more Program Category slugs
* group: one or more Program Group IDs or slugs
* level: one or more Level IDs or slugs
* season: current, upcoming, future, completed, all, a Season slug, a Season post ID, or a comma-separated combination; defaults to current,upcoming,completed
* status: coming_soon, open, closed, all, or a comma-separated combination; defaults to coming_soon,open,closed
* heading: optional heading above the catalog
* group_levels: yes or no; defaults to yes
* group_program_groups: yes or no; defaults to yes
* open_sessions: yes or no; defaults to no so Session accordions begin collapsed
* open_groups: yes or no; defaults to no so Program Group accordions begin collapsed
* open_levels: yes or no; defaults to no so Level accordions begin collapsed
* open_first: yes or no; opens only the first Session and remains available for older shortcodes
* show_dates: yes or no; controls the Session date range
* show_registration_dates: yes or no; defaults to yes and controls the Season registration opening and closing line
* show_price: yes or no
* empty: custom empty-results message

Published empty Upcoming Seasons can match `sport` and `format` because those classifications also exist on the Season itself. Sport and Format values are resolved against both the saved term slug and its normalized visible name, so `sport="hockey"` also matches an older Hockey term whose stored slug is `h`. Category, Program Group, and Level filters require actual matching Programs or Levels and therefore do not display an empty Season.

Examples:

[if_programming sport="figure-skating" format="class"]

[if_programming sport="hockey" format="league"]

[if_programming category="learn-to-skate"]

[if_programming category="homeschool" group_program_groups="no"]

[if_programming category="adaptive" group_program_groups="no"]

[if_programming group="adult"]

[if_programming level="adult-1"]

[if_programming season="all" status="coming_soon,open"]

[if_programming sport="figure-skating" open_first="yes" heading="Skating Classes"]

Every Season edit screen includes its exact post-ID shortcode in a side box with a Copy Shortcode button. The post-ID form remains valid if the Season title or URL slug changes.

The Session, Program Group, and Level accordions use native HTML details and summary elements. They do not require JavaScript and remain keyboard accessible. Class choices are compact responsive rows containing the class name, day and time, full-session price, and registration action or current status. Within a Current Season, classes whose last possible scheduled weekday has passed are hidden automatically; mixed Levels retain only the classes that still have an occurrence remaining. Use `hide_finished="no"` when an archival or informational page should retain them. The routed Adult Development Camp drop-in remains available because its registration destination supplies its changing future dates. Within each displayed list, scheduled classes appear Monday through Sunday and earliest-to-latest on the same day; unrecognized schedules appear afterward. Drop-In Programs show both the calculated per-class rate and the recalculated package price for the scheduled weeks still remaining. When the remaining dates cannot be determined safely, only the per-class rate is shown. A paid one-session non-Drop-In class is labeled Per Class instead of being presented as a one-week package.

For a compact day-by-day summary like the Learn to Skate Class Offerings blurb, use:

[if_programming_offerings sport="figure-skating" format="class"]

[if_class_offerings] is provided as a shorter alias. The offering list reads the published Programs, displays their parent Level names once per day, and compresses consecutive numbered Levels into ranges such as Snowplow Sam 1–4 and Basic 1–6. Within each day it lists Figure Skating first, Hockey second, and other sports afterward; each sport is sorted by starting age from youngest to oldest.

Offering-list attributes:

* sport, format, category, group, and level: the same filters used by the full catalog
* season: current, upcoming, current,upcoming, a Season slug, or a Season post ID; defaults to current
* status: coming_soon, open, closed, all, or a comma-separated combination; defaults to coming_soon,open
* heading: optional custom heading; otherwise Current Class Offerings or Upcoming Class Offerings is generated automatically
* show_season: auto, yes, or no; auto includes Season names when more than one Season matches
* show_dates: yes or no; defaults to no
* show_times: yes or no; defaults to no; when enabled, offerings sharing a time are grouped beneath that time and time groups are sorted earliest to latest within each day
* show_unscheduled: yes or no; defaults to yes and places unrecognized schedules under Schedule TBD
* combine_seasons: yes or no; defaults to yes so matching regular and Specialty Seasons share one Current or Upcoming list
* include_show: yes or no; defaults to no so Show Seasons and volunteer assignments stay out of normal Class Offerings
* empty: custom empty-results message

Examples:

[if_programming_offerings season="upcoming" sport="figure-skating" format="class"]

[if_programming_offerings season="current,upcoming" category="learn-to-skate" show_dates="yes"]

[if_programming_offerings category="homeschool" show_times="yes"]

[if_programming_offerings season="123" heading="Summer Class Offerings:"]

== Display Settings ==

Programming > Settings controls the public catalog without requiring CSS edits:

* Accent, heading, Season, Program Group, Level, class, body text, muted text, shared border, and independent Season-border colors
* Register Now button background and text colors, plus separate hover colors
* Registration Open, Coming Soon, Registration Closed, and default button wording
* Compact, comfortable, or spacious display density
* Internal section spacing, separate spacing above and below the complete catalog, Season border width, and independent Season and inner-section corner radii
* Base, Session, Program Group, Level, and class-name type sizes
* Program Group, Level, and distinct class-description visibility

Program Group and Level descriptions appear only inside their expanded sections. A class description is shown beneath its class name when enabled, but League text duplicated by an older flat import is suppressed.

== Shared Dash Connector ==

Programming never stores Dash credentials.

The Dash Connection screen reports the shared Connector status and provides links to its Object Explorer and Learned Schema. Dash Preview & Sync lets an administrator choose a Season, review the League > Team hierarchy plus any League without Teams, select the desired Programs and Levels, and explicitly run a protected import.

Dash imports remain draft-first by default. The Season Discovery Inbox is the starting point for new imports and later refreshes. New Seasons open the existing protected preview, Imported Seasons offer a direct Sync shortcut to that preview, and Changed Seasons show a compact source-difference summary before opening Review Changes. An empty Dash Season can be imported by itself without creating placeholder Levels or Programs. Its optional publish setting publishes only the Season. When Team classes exist, the optional Publish imported records immediately setting publishes the selected Team-based Programs, their League-based Levels, and Season during the same import, including previously imported drafts selected again. Program Groups are assigned locally after import. Without the publish option, existing linked Seasons, Levels, and Programs keep their publication status and local presentation by default. Six separate unchecked options can adopt the current Dash name or description independently for the Season, selected parent or standalone Levels, and selected classes. Description updates use the previously adopted Dash text as a safety baseline: changed or removed Dash descriptions update WordPress only when the website copy still matches that baseline, while locally added or edited writing remains untouched. Class description updates apply the same protection to generated excerpts. The import refreshes source relationships, dates, schedule, age range, full-session price, generated registration destination, availability, enrollment, capacity, and registration state. Groups, categories, ordering, button text, and locally customized registration links remain protected. Nothing is deleted or unpublished by the importer.

A standalone League row imports its League description, age range, registration destination, and Season relationship directly into a Level. It can be published during import and later assigned to Program Groups or Categories like any other Level.

No scheduled discovery or synchronization runs in the 1.9.1 maintenance line. The Monitoring screen stores the rules that 1.9.2 will use, while the admin inbox continues loading read-only cached discovery data when opened and can be refreshed manually. The public catalog continues rendering from local WordPress data if Dash is temporarily unavailable.

Live discovery established this source mapping:

* Season: registration opening/closing and overall dates.
* League: the Season-linked Level, description, and age range.
* Team: the Level-linked registrable Program, including day/time and active state.
* Product: per-class pricing combined with the Team class count to produce a full-session price.
* Team Registration Info: registration status, enrollment, capacity, and waitlist availability.
* Connector company + Team ID: the individual class registration link.
* Connector company + Team facility + League ID: the broader DaySmart Program Level link.

The protected synchronization layer:

* Store imported source values separately from local overrides.
* Preserve names, descriptions, visibility, ordering, categories, and button wording by default; Season, Level, and class names or non-empty descriptions can be explicitly adopted for one sync.
* Keep rendering local WordPress records if Dash is temporarily unavailable.

== Development Roadmap ==

The maintained development roadmap is included in ROADMAP.md.

* 1.8: shipped — a manual Season Discovery Inbox with New, Imported, and Changed indicators; direct Current and Upcoming listings; an All Seasons view; Sync shortcuts; and reversible Never Import exclusions.
* 1.9.1: shipped — monitoring configuration by Season family and a bounded local Activity Log, with all scheduling and email still off.
* 1.9.2: guarded scheduled discovery and refreshes, including registration-close signals and imported Current/Upcoming Season comparisons.
* 1.9.3: deduplicated new-Season email links that open the protected admin review workflow.
* 2.0: opt-in automatic import for explicitly enabled Season families, followed by clear success or needs-review announcement emails.

== Changelog ==

= 1.9.1.10 =

* Grouped Season Discovery into New, Needs Re-sync, Imported, and Never Import sections, with New Seasons first.
* Added a prominent prompt when newly discovered Seasons are ready to review and import.
* Added protected three-way description syncing for Seasons, Levels, and classes.
* Allows a description removed in Dash to clear the previously imported Dash copy while preserving anything added or edited in WordPress.
* Applies the same protection to generated class excerpts.

= 1.9.1.9 =

* Uses the exact existing League-style Coming Soon button and configured Coming Soon text for class-based Upcoming Seasons.
* Keeps the full registration opening date in the Season details instead of repeating a shortened date inside the button.
* Prevents Coming Soon status buttons from shifting vertically on hover.

= 1.9.1.8 =

* Generalized the existing League Coming Soon treatment to every Upcoming Season, including class-based Seasons that already contain Levels and Programs.
* Uses a muted, non-clickable `Registration opens Aug/24` Season button when a registration opening date is available.
* Falls back to the configured Coming Soon label when the opening date is not known.
* Prevents an Upcoming Season from exposing an active Season registration shortcut before its effective opening date.

= 1.9.1.7 =

* Grouped time-enabled Class Offerings beneath shared time or time-range labels instead of repeating a time after every Level.
* Sorted each day's time groups from earliest to latest, with Time TBD last.
* Kept the default no-time Class Offerings output unchanged.

= 1.9.1.6 =

* Added optional times to compact Class Offerings with `show_times="yes"`; times remain hidden by default.
* Keeps Levels sharing the same time compressed into ranges and separates multiple time slots for the same Level.
* Displays Time TBD when a filtered offering has no dependable time value.

= 1.9.1.5 =

* Added dual Drop-In pricing such as `$30 per class` or `$90 for remaining 3 weeks`.
* Recalculates the remaining package price from the imported session total, class count, weekday schedule, and dates still remaining.
* Shows only the per-class rate when a dependable remaining-date count is unavailable.
* Kept the two Drop-In choices compact and right-aligned on desktop and mobile.

= 1.9.1.4 =

* Hid classes from Current Season accordions once their last scheduled weekday has passed, while retaining uncertain schedules and the specially routed Adult Development Camp drop-in.
* Kept mixed Levels visible with only their remaining classes and removed Levels that no longer contain any upcoming class dates.
* Added `hide_finished="no"` for pages that intentionally need to retain finished Current Season classes.
* Displayed paid one-session offerings as Per Class with their single-class rate and no misleading one-week duration.

= 1.9.1.3 =

* Matched compact Class Offerings to the website's exact grey body-copy color.
* Sorted each day's Class Offerings by Sport—Figure Skating, then Hockey, then other sports—and by starting age from youngest to oldest within each Sport.

= 1.9.1.2 =

* Excluded Programs from Show-named Seasons from compact Class Offerings by default, with `include_show="yes"` available when wanted.
* Combined shared “for Hockey” Level suffixes into ranges such as Snowplow Sam 1–3 for Hockey.
* Made the full summary inherit the website's surrounding default text color, including its bold heading and weekday labels.

= 1.9.1.1 =

* Added compact day-by-day Level summaries with `[if_programming_offerings]` and `[if_class_offerings]`.
* Supports Current, Upcoming, combined, and specific-Season selections plus the catalog's Sport, Format, Category, Group, Level, and registration-state filters.
* Combines matching Seasons by lifecycle by default, allowing regular and Specialty Seasons to share one Current or Upcoming list.
* Compresses consecutive numbered Levels into labels such as Snowplow Sam 1–4 and Basic 1–6.
* Keeps incomplete schedule information visible under Schedule TBD by default.

= 1.9.1 =

* Added a Monitoring screen with global pause/readiness, planned frequency, discovery signals, notification address, and activity-retention settings.
* Added configurable Season families with independent switches and Dash Season name matching phrases.
* Added a bounded local Activity Log for settings, manual discovery, exclusions/restorations, protected syncs, warnings, and failures.
* Kept scheduled Dash checks, email, automatic imports, deletions, unpublishing, and protected-presentation replacement off.

= 1.8.6 =

* Extended the opt-in Dash presentation controls to Season and Level names and descriptions.
* Grouped all six Season, Level, and class choices into a compact optional-update panel.
* Made Season presentation updates available for empty Season-only imports.
* Kept blank descriptions non-destructive and added per-layer update counts to the completion notice.

= 1.8.5 =

* Added opt-in sync controls to update selected class names from current Dash Team names.
* Added an independent option to replace selected class content and excerpts with non-empty Dash Team descriptions.
* Kept both replacements off by default and reported their actual update counts after synchronization.

= 1.8.4 =

* Free one-off Programs now show their exact event date and time in the schedule column.
* Removed the separate duration column from those rows along with the $0 price information.

= 1.8.3 =

* Free one-day Programs now omit the Session Price label and $0 value.
* Replaced the misleading “for 1 week” duration with an italic “for one day” label.

= 1.8.2 =

* Added a Season Shortcode box to every Season edit screen.
* Uses the stable WordPress Season post ID so the shortcode survives title and slug changes.
* Added a one-click Copy Shortcode button with accessible confirmation and clipboard fallback.

= 1.8.1 =

* Automatically loads cached Season discovery data when the admin screen opens.
* Automatically performs the comparison pass when discovery cache data is unavailable.
* Renamed the Changed state to the clearer Needs Re-sync flag and added a prominent summary alert.
* Added Season checkboxes, Select All/Deselect All controls, and an Exclude Selected bulk action.
* Kept explicit Refresh Discovery as the force-fresh option while automatic loading uses the Connector cache.

= 1.8.0 =

* Added the Season Discovery Inbox with direct Current and Upcoming Season listings.
* Added New, Imported, and Changed indicators with Preview / Import, Sync, and Review Changes actions that open the protected workflow.
* Added compact successful-sync snapshots and summaries for Season, Level, and class additions, removals, and meaningful source changes.
* Added an All Seasons view with Current, Upcoming, Completed, Imported, New, Changed, and Excluded filters.
* Added reversible Never Import exclusions keyed to immutable Dash Season IDs.
* Added WordPress publication state, lifecycle, last sync, last check, and protected-local-field visibility to imported Season rows.
* Kept discovery fully manual; no scheduled requests, imports, deletions, or publication changes were introduced.

= 1.7.37 =

* Added a maintained 1.8–2.0 roadmap for discovery, exclusions, guarded monitoring, email review links, and opt-in automatic importing.
* Added explicit Season routing for Dash League #86 and its Programs so the drop-in Level follows only the published Current Learn to Play Season.
* Preserved #86's last local Season assignment when no Current Learn to Play Season is temporarily available, rather than moving it to an Upcoming or unrelated Dash Season.
* Safely pauses a first-time #86 import when no published Current Learn to Play Season is available.
* Added clear routing notices to the Dash preview and Level editor.

= 1.7.36 =

* Added Coming Soon bubbles to every upcoming League-format Season.
* Added matching bubbles to standalone and Team-backed League Levels.
* Detects League format from both Season classifications and contained Programs without affecting other formats.

= 1.7.35 =

* Added Coming Soon bubbles to upcoming standalone League Season and Level headers.
* Reused the configurable Coming Soon wording and responsive Register Now alignment.
* Automatically replaces those status bubbles with live registration shortcuts when registration opens.

= 1.7.34 =

* Added selectable standalone League rows for Dash Leagues that have no Team/class records.
* Imported those rows as real Season-linked Levels without creating placeholder Programs.
* Retained their descriptions, ages, and broader DaySmart Level registration links.
* Displayed published standalone Levels in filtered public catalogs and their assigned Program Groups.
* Added open-registration shortcuts while retaining automatic Coming Soon and Registration Closed behavior.

= 1.7.33 =

* Allowed Program Categories to be assigned to either Levels or individual Programs/classes.
* Made category shortcodes match direct Program assignments and categories inherited from the linked Level.
* Added built-in Homeschool and Adaptive categories.
* Automatically categorized existing and future HS-prefixed classes as Homeschool without removing manual categories.
* Automatically assigned Adaptive to the Adaptive Level, allowing all of its classes to inherit the category.
* Added Level category visibility and inherited-category labels to the admin lists.
* Added ready-to-use Homeschool and Adaptive shortcode examples.

= 1.7.32 =

* Removed doubled vertical padding between every expanded Season header and its contents.
* Applied the tighter seam whether the Season begins with a description, Program Group, Level, or class list.
* Preserved the normal spacing between items inside the expanded Season.

= 1.7.31 =

* Displayed each Season description at the top of its expanded catalog contents.
* Added a Display Settings toggle for showing or hiding Season descriptions.
* Kept descriptions hidden while their Season accordion is collapsed.
* Matched Season-description typography and spacing to the existing Group and Level descriptions.

= 1.7.30 =

* Added an editable Display Order to every Season.
* Kept Seasons chronological by start date and then end date.
* Applied lower-number-first ordering only when both Season dates match.
* Preserved the existing rule that completed Seasons remain grouped at the bottom.
* Added Display Order to the Seasons admin list for quick review.

= 1.7.29 =

* Corrected whole-year age labels by including Dash's age-month boundaries while preserving manually edited ranges.
* Sorted Levels within each Program Group from youngest to oldest.
* Made the selected Program Group background consistent across its heading and expanded content.
* Added independent border colors, border widths, and corner-radius controls for Program Groups, Levels, and class rows.

= 1.7.28 =

* Added independent Season Border color and width controls.
* Kept the Season outline separate from all inner borders and dividers.
* Preserved the previous generated outline color when upgrading.

= 1.7.27 =

* Extended the Season background across its heading and complete expanded area.
* Added independent Level and class background colors.
* Preserved existing colors when upgrading by initializing the new settings from the previous shared surface color.

= 1.7.26 =

* Removed the public catalog's outer border, background, corner treatment, and inset padding.
* Added a separate Season Corner Radius setting.
* Retained independent corner control for Program Groups and Levels.

= 1.7.25 =

* Added Program Categories to the Programs admin list.
* Linked assigned category names to their filtered Program views.
* Highlighted Programs with no category as Not assigned.

= 1.7.24 =

* Added separate settings for spacing above and below the complete public Programming catalog.
* Applied the outer spacing to shortcode sections on desktop and mobile.
* Defaulted both settings to zero to preserve existing layouts after upgrading.

= 1.7.23 =

* Removed the extra Other Programs accordion from the public catalog.
* Displayed Levels without a Program Group directly beneath all defined Program Group accordions.
* Preserved each unassigned Level as its normal collapsible Level with its description, classes, and registration shortcut.
* Kept defined Program Groups in their configured order and unassigned Levels afterward.
* Applied the configurable Registration Closed label to completed Season status bubbles.

= 1.7.22 =

* Sorted public class rows by weekday from Monday through Sunday.
* Sorted classes on the same weekday by their starting time.
* Applied the same schedule order to direct class lists, Program Groups, and Levels.
* Placed unrecognized weekdays after scheduled weekdays and unrecognized times after timed classes on the same day.
* Retained Program page order and title as deterministic fallbacks when schedules match.

= 1.7.21 =

* Matched Sport and Format shortcode values against both stored term slugs and normalized visible term names.
* Restored existing hockey classes whose legacy Hockey term uses the abbreviated slug `h`.
* Allowed `sport="hockey" format="class"` to include matching current and completed class Programs.
* Kept matching empty Upcoming hockey League Seasons in Sport-only catalogs.
* Changed future imports to assign real term IDs instead of treating classification slugs as new term names.
* Preserved existing taxonomy terms and relationships without requiring re-import or manual retagging.

= 1.7.20 =

* Added Sport and Format classification directly to Seasons as well as Programs.
* Classified Season-only imports from available Dash fields and conservative Season-name matching.
* Included published empty Upcoming Seasons when `sport` or `format` shortcode filters match.
* Added a display-time fallback for already imported Seasons, so they do not need to be imported again.
* Kept Program Category, Program Group, and Level filters strict until matching Program records exist.
* Continued showing empty Upcoming Seasons without registration links as compact Coming Soon rows.

= 1.7.19 =

* Hardened the Season-only and static Season display changes after a site-specific upgrade failure.
* Replaced template-level loop continuation with an explicit static-or-accordion rendering branch.
* Changed empty Upcoming Season discovery to a lower-memory ID-only query.
* Validated every returned Season before adding it to the public catalog.
* Released temporary unfiltered Program query results before rendering.

= 1.7.18 =

* Added a dedicated Season-only import when a Dash Season has no Team or Level records.
* Kept Season-only imports draft-first with an explicit option to publish the Season immediately.
* Prevented an empty Team selection from being mistaken for a Season-only import.
* Included published empty Upcoming Seasons in unfiltered public catalogs.
* Displayed empty Upcoming Seasons as compact, non-expandable rows with dates and a non-clickable Coming Soon status.
* Automatically returned a Season to the normal accordion display once published Programs are available.

= 1.7.17 =

* Replaced completed Season accordions with compact, non-expandable rows.
* Reduced completed rows to the Season name and the non-clickable Registration Closed status.
* Lightened completed Season heading text for clearer visual hierarchy.
* Continued sorting completed Seasons at the bottom of the catalog.

= 1.7.16 =

* Added a muted Registration Closed status pill to closed Season headers.
* Changed closed Season heading text to dark grey.
* Sorted closed Seasons after open and upcoming Seasons while retaining date order within each group.
* Kept the closed status non-clickable and continued suppressing registration links after registration closes.

= 1.7.15 =

* Added an optional Publish imported records immediately checkbox to Dash Sync.
* Published selected Programs, their parent Levels, and the Season in the same import when explicitly selected.
* Allowed previously imported drafts to be published by selecting them again with the publish option.
* Kept draft-first imports as the unchecked default.
* Continued preserving publication status during ordinary refreshes and never unpublished existing records.
* Added the number of records published to the import result notice.

= 1.7.14 =

* Allowed multiple Seasons to be marked Current without changing other active Seasons to Completed.
* Updated current-season shortcode filtering to return every published Current Season.
* Updated the Programming Dashboard to display every Current Season.
* Included Current, Upcoming, and Completed Seasons in the default catalog.
* Automatically treated Upcoming Seasons as Current once registration opens.
* Recovered Seasons incorrectly marked Completed by the former single-Current limitation when their registration window is still open.
* Changed the public registration line to “Registration opened on” after the opening date passes.
* Automatically replaced registration dates with a closed-date message after registration ends.
* Removed Season, Level, and class registration links from closed or Completed Seasons.
* Added Seasons-screen prompts to mark automatically opened Seasons Current or overdue Seasons Completed.

= 1.7.13 =

* Seeded editable starter Program Categories so the WordPress checklist is no longer blank.
* Added Learn to Skate, Learn to Play, Specialty Classes, and Camps & Clinics starter categories.
* Changed the default catalog to include Current and Upcoming Seasons.
* Added comma-separated Season selectors while keeping explicit current-only and all-season shortcode controls.
* Renamed the Programming submenu item from Categories to Program Categories.

= 1.7.12 =

* Moved the configurable accent stripe from individual classes to their parent Level.
* Changed class cards into a continuous compact list with simple row separators.
* Applied the flatter Level-and-list treatment on both desktop and mobile.
* Kept the accent and registration buttons synchronized with their configured hover color.

= 1.7.11 =

* Hid the Session Price label in the phone layout.
* Placed the price and week count beside the Day & Time value on mobile.
* Reduced the vertical height of mobile class cards without changing desktop pricing.

= 1.7.10 =

* Right-aligned the complete Session Price block, including its label, price, and week count.
* Kept the right alignment in the responsive phone layout.

= 1.7.9 =

* Restored Register Now buttons on individual open class rows.
* Linked each class button directly to its DaySmart Team registration page.
* Kept Level buttons linked to their broader League/Level registration destination.
* Preserved manual class registration-link overrides.
* Retained the compact single-line session price and week count.

= 1.7.8 =

* Shifted Level registration shortcuts farther right for clearer separation from their accordion chevrons.
* Left Season shortcut positioning unchanged.

= 1.7.7 =

* Aligned Season shortcut buttons vertically with their accordion chevrons.
* Moved Season and Level shortcut buttons to the right edge of their rows.
* Removed repeated Register Now buttons from individual open class rows.
* Kept closed and coming-soon status badges on the affected classes.
* Displayed session price and italicized week count together on one line.

= 1.7.6 =

* Added Register Now shortcuts to collapsed Season and Level accordions.
* Linked Season shortcuts to the selected DaySmart Program and Season catalog.
* Reused the DaySmart Level destination for Level shortcuts.
* Added optional manual Registration URL fields to Seasons and Levels.
* Kept shortcut buttons hidden unless at least one displayed class is open.
* Opened all shortcut destinations in a new browser tab.

= 1.7.5 =

* Opened public Register Now destinations in a new browser tab.
* Added safe external-link relationship attributes.

= 1.7.4 =

* Imported the Dash Team class count as the Program's session length in weeks.
* Displayed “for 8 weeks,” “for 7 weeks,” and similar context beneath public session prices.
* Added an editable Session Length (Weeks) field for manual adjustments.

= 1.7.3 =

* Matched each class row's accent stripe to its Register Now button color.
* Changed both the stripe and button to their configured hover colors when the whole class row is hovered or receives keyboard focus.

= 1.7.2 =

* Kept Register Now buttons stationary while their hover colors change.

= 1.7.1 =

* Added Select all and Deselect all controls above the Dash Sync preview.
* Added a live count of the Team classes selected for import.
* Limited bulk selection to eligible Team rows.
* Added normal and hover background/text color settings for Register Now buttons.
* Defaulted the Register Now hover background to Ice & Field orange.

= 1.7.0 =

* Added automatic DaySmart registration links for imported Team classes.
* Reused the proven Productions deep-link format based on the Team's parent League ID.
* Included the Connector company and Team facility ID in every generated registration destination.
* Added registration-link readiness to the Dash preview before import.
* Kept manually entered Program registration URLs protected from later Dash refreshes.

= 1.6.0 =

* Allowed each Level to belong to multiple Program Groups.
* Added checkbox-based Group selection to the Level editor.
* Repeated a Level and its classes beneath every assigned Group in the public catalog.
* Updated Group filtering and Level-list visibility for multi-Group assignments.
* Continued preserving all local Group assignments during Dash imports and refreshes.

= 1.5.1 =

* Corrected WordPress taxonomy navigation labels so Program Group screens return to Program Groups instead of Categories.
* Added explicit parent and empty-list labels for all Programming taxonomies.

= 1.5.0 =

* Added reusable Program Groups between Seasons and Levels.
* Added starter Groups for Basic Skills, Adult, Free Skate, Snowplow Sam, and Hockey.
* Added a single Program Group selector to every Level.
* Added editable Group descriptions and numeric Display Order, with lower numbers appearing first.
* Added the collapsed Program Group layer to the public catalog.
* Kept unassigned Levels visible under an Other Programs group displayed last.
* Added Program Group admin filtering, Level-list visibility, shortcode filtering, heading-size settings, and description controls.
* Preserved all local Group assignments during Dash imports and refreshes.

= 1.4.2 =

* Simplified the Season registration window to dates only.
* Removed bold registration labels and styled the complete line in italic.

= 1.4.1 =

* Added the Season registration opening and closing dates beneath the Season date range.
* Included registration times using the site’s configured date and time formats.
* Added a show_registration_dates shortcode control and a stacked phone layout.
* Gracefully displays either boundary when only an opening or closing date is available.

= 1.4.0 =

* Added Session accordions as the public catalog’s first layer.
* Kept Sessions and Levels collapsed by default, with shortcode controls for either layer.
* Moved Level descriptions inside expanded Level panels.
* Replaced large per-class accordions with compact rows for class name, day and time, full-session price, and registration action or status.
* Preserved distinct class descriptions while suppressing duplicated Level text.
* Added responsive tablet and phone layouts.
* Added settings for catalog colors, display density, spacing, corner radius, type sizes, and description visibility.
* Restyled the catalog as a restrained light surface that fits the existing Ice & Field Learn to Skate page.

= 1.3.1 =

* Added a self-contained light background and readable text colors to the public catalog.
* Converted Level groups into native accessible accordions.
* Collapsed all Levels by default, with an optional open_levels shortcode control.
* Moved each Level description directly beneath its name in the collapsed header.
* Kept Team/Class accordions nested inside their parent Level.
* Kept distinct Program descriptions visible while suppressing League text duplicated by older flat imports.

= 1.3.0 =

* Added Level records as the missing middle layer between Seasons and Programs.
* Mapped each selected Team’s parent Dash League to one Season-linked Level.
* Linked imported and refreshed Programs to their Level without creating duplicates.
* Added Level management, Season and Program relationships, list columns, and admin filters.
* Grouped public Program accordions beneath Level headings by default.
* Added level and group_levels shortcode controls.
* Preserved existing flat Level labels as a fallback for older and manual Programs.

= 1.2.1 =

* Fixed a Dash Preview regression that could replace the availability lookup table with one row’s display text.
* Restored Team registration status, enrollment, capacity, and waitlist matching throughout the preview.

= 1.2.0 =

* Added an administrator-triggered protected import for selected Dash Teams.
* Created new Seasons and Programs as drafts; nothing is automatically published or deleted.
* Added duplicate-safe source linking for later refreshes.
* Preserved local titles, descriptions, excerpts, visibility, ordering, taxonomies, registration links, and button text.
* Imported full-session price, dates, schedule, level, ages, availability, capacity, waitlist, and registration state.
* Kept scheduled synchronization disabled for the next roadmap milestone.

= 1.1.1 =

* Changed Dash Preview pricing to show the full session price.
* Multiplied the Product’s per-class price by the Team’s class count.
* Kept flat-fee Products as a single session price.
* Added the price calculation beneath each total for easy verification.

= 1.1.0 =

* Completed live Dash discovery for Seasons, Leagues, Teams, Products, Sports, and Team Registration Info.
* Confirmed that Teams are the registrable class records.
* Added a Season-selectable, read-only Dash import preview.
* Added proposed create/update, Sport, Format, schedule, availability, and price-source review.
* Added batch availability discovery without enabling writes or scheduled synchronization.

= 1.0.0 =

* Initial local-first Programming architecture.
* Added Seasons and Programs.
* Added Sports, Formats, and Program Categories.
* Added registration lifecycle calculation and Season date fallback.
* Added accessible filtered accordion shortcodes.
* Added Programming dashboard and display settings.
* Added shared Dash Connector boundary and protected future-sync fields.
