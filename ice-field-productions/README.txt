=== Ice & Field Productions ===
Stable tag: 3.7.1
Requires at least: 6.5
Requires PHP: 7.4

Production management, Programming companion projection, participant resources, sponsors, show archives, and front-end displays for Ice & Field skating productions.

== Installation ==
1. In WordPress, open Plugins > Add New > Upload Plugin.
2. Upload the complete ice-field-productions-v3.1.24 ZIP.
3. Choose Replace current with uploaded if WordPress detects an earlier version.
4. Activate the plugin if needed.

Ice & Field Productions requires both Ice & Field Dash Connector and Ice & Field Programming. The connector owns the DaySmart/Dash credentials, OAuth token, pagination, request caching, and API diagnostics. Programming is the primary Season discovery and synchronization workflow, and Productions projects Production-designated Seasons into Production companions.

== Current Features ==
- Production lifecycle: Draft, Upcoming, Current, Completed, and Archived
- Complete Dash Season import with Divisions, Groups, and shared People
- Production dashboard, Homepage Builder, Participant Hub, and public production pages
- Production-scoped notices, resources, Important Dates, contacts, and sponsors
- Group rosters, People profiles, CSV export, emergency contacts, and Dash synchronization
- Reviewed HTML email to Productions, Divisions, Groups, or selected People
- Reusable Email Templates and local per-message/per-Person Communication History
- Duplicate-safe matching by Dash IDs rather than names
- A concise grouped admin menu with dedicated hubs for Productions, People & Groups, Participant Content, Website, and Dash tools
- Automatic Dash registration links for imported Productions, Divisions, and Groups, with protected manual overrides
- Lifecycle-aware Production URLs: Current shows use the Participant Hub while Upcoming and past shows use public show-focused pages
- Optional multi-day date ranges for Important Dates

== Shortcodes ==
[ifp_homepage]
[ifp_current_production]
[ifp_countdown]
[ifp_sponsors]
[ifp_participant_hub]
[ifp_participant_notices]
[ifp_participant_resources]
[ifp_groups]
[ifp_registration]

Use `[ifp_participant_hub production_id="123"]` to show the Participant Hub for one specific published Production. Visiting that Production's public `/productions/production-name/` URL applies this automatically.
Use `[ifp_registration production_id="123"]` to show one Production's registration options accordion on its own page. It follows the saved registration opening and closing dates automatically.

== Version 3.1.24 — Logo-Left Production Heroes ==
- Removes the outer border, accent edge, and internal divider from the homepage hero card.
- Retains one continuous translucent background, rounded corners, and shadow around the complete showcase.
- Enlarges the Production logo in a dedicated left column on both the homepage and individual Production pages.
- Keeps the Production page's title, tagline, dates, description, status details, and actions in their existing hierarchy.
- Centers each Production logo on phones and lets it use the full width of its logo area.

== Version 3.1.23 — Configurable Countdown Text Color ==
- Adds a Countdown Text Color control to each Production's Branding settings and Guided Setup.
- Defaults existing and new Productions to white countdown numbers and labels.
- Keeps the Production Secondary Color as the countdown tile background.
- Applies the chosen text color consistently to both direct Production pages and the homepage.
- Retains independent Homepage Builder countdown colors when “Use Production colors for countdown boxes” is disabled.

== Version 3.1.22 — Production Page Countdown Colors ==
- Removes white countdown tiles only from direct Production showcase pages.
- Uses the Production Secondary Color for tile backgrounds and Accent Color for numbers and labels.
- Adds a page-specific final style so generic countdown rules cannot restore the white background.
- Leaves the homepage countdown and Homepage Builder behavior unchanged from version 3.1.21.

== Version 3.1.21 — Unified Homepage Showcase ==
- Combines the homepage logo and information columns inside one unified translucent showcase card.
- Uses a single outer border, accent line, overlay, and corner treatment instead of two separate boxes.
- Uses the Production Secondary Color for countdown tiles by default, with readable contrasting text.
- Adds a Homepage Builder toggle for returning to independent countdown tile colors when desired.

== Version 3.1.20 — Branded Homepage Showcase & Venue ==
- Rebuilds the current Production homepage hero as a responsive two-panel showcase.
- Places the Show Logo in a dedicated left panel and restores the written Production title in the information panel.
- Uses the Production Accent, Secondary, Overlay Color, and Information Box Opacity across hero panels, labels, and actions.
- Adds a reorderable Venue / Plan Your Visit homepage section with full, uncropped venue artwork.
- Stacks both the hero and venue layouts into clean single-column presentations on smaller screens.

== Version 3.1.19 — Full Venue Artwork Layout ==
- Moves the selected venue image to the right of the venue information on desktop.
- Loads and displays the complete full-size attachment without cropping it into a fixed cover area.
- Lets an illustrated venue or parking map retain its natural aspect ratio.
- Stacks venue information above the complete image on narrower screens.

== Version 3.1.18 — Relink Button Submission Fix ==
- Corrects the Relink Dash Season button on the WordPress Production editor.
- Uses a valid external form rather than a browser-ignored form nested inside WordPress's main post-edit form.
- Keeps the season selector and button in the Dash Production sidebar while submitting directly to the protected relinking action.
- Preserves nonce, permission, season-verification, and duplicate-link protections from version 3.1.17.

== Version 3.1.17 — Safe Dash Season Relinking ==
- Adds a Relink Dash Season control to the Dash Production sidebar on every Production editor.
- Suggests the most likely Dash Season from the Production's already-linked Divisions and Groups.
- Verifies the selected season with Dash before saving the relationship.
- Prevents the same Dash Season from being linked to two WordPress Productions.
- Preserves local Production content, presentation, dates, artwork, and manually protected registration links.
- Keeps a Change Linked Season control available for correcting an existing relationship.
- Displays each Production's Volunteer link after Registration in the homepage and public Production hero actions.
- Prefers the Production-specific Volunteer link in the Participant Hub while retaining the shared Site Link as a fallback.

== Version 3.1.16 — Registration Accordion & Venue Images ==
- Replaces the duplicate card-style Performance Groups display with a compact Division and Group registration accordion while registration is open.
- Uses each saved Production, Division, and Group Dash registration link at the appropriate level.
- Adds `[ifp_registration production_id="123"]` for standalone registration pages, including automatic pending and closed messages.
- Adds a copy-ready shortcode to each Production's Public Page settings.
- Adds Registration Opens and Registration Closes controls to Guided Setup.
- Adds a Media Library Venue Image selector and displays the selected image with public venue details.

== Version 3.1.15 — Upcoming Production Logos ==
- Displays each upcoming Production's configured Show Logo over its featured artwork on the main homepage.
- Keeps transparent logos clean with a restrained shadow and no added panel or background box.
- Preserves the existing featured-image card layout and title/description content.
- Leaves past-production archive cards unchanged.

== Version 3.1.14 — Independent Showcase Overlays ==
- Adds a third Production branding color dedicated to showcase overlays.
- Adds independent 0–100% opacity controls for the hero image wash and the countdown/performance-detail boxes.
- Allows the hero overlay to be fully disabled while retaining separately tinted translucent information boxes.
- Preserves the previous appearance for existing Productions until their new controls are changed.
- Includes all three controls in complete Production editing and Guided Setup.

== Version 3.1.13 — Production Showcase Branding & Layout ==
- Uses each Production's Accent Color for showcase eyebrows, labels, and action accents instead of a fixed orange.
- Uses the Secondary Color to tint the hero image overlay and translucent information panels instead of a fixed blue-gray.
- Preserves readable white hero text and the existing branded button behavior.
- Moves the Production description beneath the performance-date range in the hero.
- Removes the separate About the Show card and its obsolete public-page visibility control.
- Applies the same date-and-description hero layout to the main Productions homepage.
- Removes the redundant Production Story homepage section and its builder controls.

== Version 3.1.12 — Scheduled Ticket Availability ==
- Adds a Tickets Available On date to the Production editor and Guided Setup.
- Keeps ticket buttons inactive before that date, with a clear availability-date label.
- Activates saved ticket links automatically on the configured local date.
- Removes ticket actions automatically after the Production's Closing Date & Time while preserving the saved URL.
- Applies the ticket window consistently to Production showcases, homepage actions and pathways, and the Current Production shortcode.

== Version 3.1.11 — Production Logo Polish ==
- Removes the heavy translucent panel behind the Show Logo in the public Production About artwork.
- Places the transparent logo directly over the featured image with a restrained shadow for contrast.

== Version 3.1.10 — Multi-Day Important Dates ==
- Adds an optional End Date to Important Dates while keeping one-day entries compact.
- Shows date ranges consistently on the homepage, Participant Hub, Production showcase, and performance assignments.
- Keeps an ongoing multi-day Important Date visible in the Participant Hub through its final date.
- Validates that an End Date cannot precede its Start Date and preserves existing start/end time ranges.
- Displays the configured Show Logo over the featured artwork in the public Production page's About section.
- Labels the public Production date collection as Important Dates so registration, rehearsal, deadline, and performance entries are represented accurately.
- Prioritizes the next registration opening or closing milestone in an Upcoming Production countdown, then returns to the show opening date.

== Version 3.1.9 — Lifecycle-Aware Production Pages ==
- Keeps the Participant/Skater Hub on the direct URL only while that Production is Current.
- Gives Upcoming Productions a public show preview with artwork, story, countdown, dates, registration/tickets, public groups, venue, media, and sponsors.
- Gives Completed and Archived Productions a show memory page without registration or ticket actions.
- Automatically changes the same Production URL between showcase and Participant Hub presentations as its lifecycle changes.
- Adds `show_registration="0"` support to `[ifp_groups]` so past Production cast lists never show stale signup buttons.

== Version 3.1.8 — Scheduled Production Lifecycle ==
- Adds a Become Current On date to Guided Setup and the complete Production editor.
- Automatically promotes a published Upcoming Production to Current on its scheduled date.
- Automatically moves a Current Production to Completed after its Closing Date & Time passes.
- Runs an hourly WordPress scheduled check plus a throttled visit-time fallback so normal site traffic catches delayed Cron checks.
- Processes overdue scheduled Productions chronologically and leaves only the newest eligible Production Current.

== Version 3.1.7 — Guided Setup for Existing Productions ==
- Keeps detailed admin screens registered with WordPress while hiding their duplicate menu rows visually, fixing the permission error on Guided Setup and other nested shortcuts.
- Lets Guided Setup load an existing Draft, Upcoming, or Current Production instead of always creating a new one.
- Prefills Production details, dates, colors, links, and matching starter Important Dates.
- Updates the selected Production in place, preserves its lifecycle by default, and avoids duplicate starter dates.
- Adds a Guided Setup shortcut to eligible rows in the Productions list.

== Version 3.1.6 — Dash Registration Links ==
- Generates a filtered DaySmart registration URL for imported Production Seasons, Divisions/Leagues, and Groups/Teams using the same routes proven in Ice & Field Programming.
- Saves the generated link in an editable Registration URL field and preserves a manual replacement during future Dash syncs.
- Adds Open Registration shortcuts to Dash-linked edit screens, a Registration quick link on Production Participant Hubs, and Register buttons to public Group cards.
- Keeps teams with explicitly disabled online signup from receiving a misleading direct-registration button.

== Version 3.1.5 — Production Toolbar Shortcut ==
- Adds an Edit Production link to the top WordPress toolbar while an authorized user views a public Production Participant Hub.
- Links directly to the Production record being viewed and remains hidden from visitors without editing permission.

== Version 3.1.4 — Production Permalink Repair ==
- Adds an explicit top-priority rewrite for `/productions/{production-name}/` URLs.
- Adds a safe request-level fallback when WordPress's stored rewrite table or another component omits the normal custom-post-type rule.
- Keeps normal publication and lifecycle privacy checks in place after resolving the Production.

== Version 3.1.3 — Production Participant Hubs ==
- Makes every published Production URL render the Participant Hub for that exact Production.
- Scopes notices, Important Dates, resources, contacts, artwork, and the countdown to the Production in the URL instead of always using the globally Current Production.
- Adds an optional `production_id` parameter to `[ifp_participant_hub]` and `[ifp_countdown]`.
- Refreshes Production permalink rules once after upgrading so existing `/productions/.../` URLs resolve correctly.

== Version 3.1.2 — Navigation Refinements ==
- Reliably removes the separate WordPress Productions post-type menu after all admin menus have been registered.
- Keeps the grouped Ice & Field Productions menu and every direct Production edit link working normally.
- Adds expandable shortcut menus beneath Productions, People & Groups, Communication, Participant Content, Website, and Dash.
- Keeps each section hub available while making its detailed screens reachable directly from the WordPress sidebar.

== Version 3.1.1 — Grouped Admin Navigation ==
- Keeps the visible Productions submenu to Dashboard, Productions, People & Groups, Communication, Participant Content, Website, Dash, and Settings.
- Opens a visual hub of related tools from each main section instead of placing every detailed screen in the primary menu.
- Keeps existing direct links, bookmarks, edit screens, and permissions intact.
- Highlights the appropriate main section while working in a detailed screen.

== Version 3.1.0 — Communication Center ==
- Compose HTML email with a simple visual WordPress editor.
- Choose an entire Production, Division, Group, or several individual People.
- Review the resolved recipient names and unique addresses before sending.
- Send one private copy to each unique address so recipients never see one another's email addresses.
- Include valid addresses from manual Group roster rows as well as linked People.
- Save reusable Email Templates or load an existing template into the composer.
- Review local message history, audience, sender, HTML body, and per-address mail-service handoff result.
- See the latest Communication History inside each Person record.
- Open the composer directly from a Production, Division, Group, Person, or filtered People list.

“Handed to mail service” means WordPress accepted the message for sending. It does not confirm final delivery or that the recipient opened it. Version 3.7.1 displays those outcomes only when a compatible provider supplies authenticated events.

== Version 3.0.2 — Stabilization and Privacy ==
- Centralized Current Production and lifecycle handling.
- Migrates older productions that do not yet have lifecycle metadata.
- Preserves publication status and local visibility when Dash records are re-imported.
- Keeps Current-only content off unrelated archived production pages.
- Protects private production content from anonymous REST API access.
- Adds dedicated People permissions for Administrators and Editors.
- Adds working Bulk Edit production assignment.
- Repairs the Upcoming Productions controls in Homepage Builder.
- Reports customer-note permission failures without treating the complete Person sync as failed.
- Updates package requirements and documentation.

Historical release notes follow.

VERSION 2.2.0 — HOMEPAGE BUILDER

Website > Homepage now provides:
- Drag-and-drop section ordering
- Show/hide controls for each homepage section
- Editable hero, pathway cards, story, participant introduction, and section headings
- Automatic current-production data, countdown, sponsors, resources, and past productions

SETUP
1. Go to Productions > Website.
2. Arrange and edit the homepage sections.
3. Save Homepage.
4. Edit your WordPress Home page.
5. Add one Shortcode block containing: [ifp_homepage]
6. Remove older production shortcodes from that page to avoid duplicated content.


VERSION 2.2.1 — HERO APPEARANCE CONTROLS

Productions > Website > Hero Appearance now includes:
- Title color
- Eyebrow color
- Tagline color
- Overlay color
- Overlay opacity
- Left, center, or right alignment
- Small, medium, large, or full-screen height
- Countdown box, number, and label colors
- Primary and secondary button background and text colors

The hero CSS now explicitly overrides generic WordPress theme heading colors so production titles remain readable over imagery.


VERSION 2.2.2 — HERO STYLE APPLICATION FIX

Fixed:
- Hero title color now applies correctly.
- Eyebrow and tagline colors now apply correctly.
- Overlay color and opacity now apply correctly.
- Countdown colors now apply correctly.
- Primary and secondary button colors now apply correctly.
- Alignment and height settings now reliably override older plugin and theme CSS.

After updating, clear any WordPress/page cache and force-refresh the browser once.


VERSION 2.3.0 — LAYOUT ENGINE AND SHOW LOGO

Production Branding:
- Featured Image remains the hero background.
- Added a separate Show Logo media upload.
- Transparent PNG logos are recommended.

Productions > Website:
- Show/hide the uploaded show logo.
- Show/hide the written production title independently.
- Set logo width, maximum height, and spacing.
- Remove theme page spacing.
- Force a full-width homepage.
- Hide the WordPress page title.
- Hide common breadcrumb elements.
- Place the hero flush against the site header.
- Adjust hero top offset and content padding.

The plugin will fall back to the written production title whenever no show logo is uploaded.


VERSION 2.3.1 — SHOW LOGO FIX AND COUNTDOWN HEADING

Fixed:
- The Show Logo attachment ID is now saved with the Production.
- Previously selected logos must be selected once more after updating because v2.3.0 did not persist the selection.
- Added stronger front-end logo visibility rules.

Added under Productions > Website > Hero Appearance:
- Show/hide countdown heading
- Countdown eyebrow text
- Countdown heading text
- Countdown eyebrow color
- Countdown heading color

Recommended:
1. Update the plugin.
2. Reopen the current Production.
3. Go to Production Setup > Branding.
4. Select the Show Logo again and click Update.
5. Confirm “Show uploaded show logo” is enabled under Productions > Website.


VERSION 2.3.2 — PATHWAY AND SPONSOR CONTROLS

Pathway cards:
- Added separate top/bottom background colors for Join, Watch, and Support.
- Added individual title, body text, and link colors.
- Added a shared card corner-radius setting.
- Replaced plain description textareas with compact WYSIWYG editors.
- Production Story fallback and Participant Hub introduction also use WYSIWYG editors.

Sponsors:
- Added a dedicated Sponsor Tagline field to each Sponsor.
- Added a show/hide tagline setting.
- Added logo corner radius, border width, border color, card background, and tagline color controls.
- Taglines display beneath sponsor logos.

Sponsor setup:
1. Open Productions > Sponsors.
2. Edit a sponsor.
3. Add its Tagline in Sponsor Details.
4. Update the sponsor.
5. Adjust display styling under Productions > Website > Sponsor Appearance.


VERSION 2.3.7 — FATAL ERROR FIX

This release was rebuilt from the exact user-uploaded v2.3.2 ZIP.

Root cause fixed:
- Earlier builds called an undefined date_card() method when no custom Important Dates existed.
- That PHP fatal error stopped the homepage midway through rendering, breaking the countdown and leaving theme containers incomplete.

This build:
- Uses the original inline opening/closing date fallback from v2.3.2.
- Does not alter the full-screen hero, page width, header, body, theme wrappers, or public JavaScript.
- Adds only full-cover story artwork, story logo overlay, and editable Important Dates.


VERSION 2.3.8 — SMALL SPONSOR AND LINK TWEAKS

Sponsors:
- Removed the large outer border around the complete sponsor grid.
- Refined spacing, logo sizing, card borders, radius, and shadow.
- Added the sponsor name above the tagline.
- Preserved existing logo radius, border, background, and tagline settings.

Website links:
- The Support pathway destination can now use either:
  1. An automatically populated WordPress page selector, or
  2. A custom URL.
- A selected WordPress page takes precedence over the custom URL.

This is intentionally a small 2.3.x release. A broader centralized link manager is deferred to 2.4.


VERSION 2.4.0 — CENTRAL LINKS, SPONSOR TIERS, ADMIN POLISH

Site Links:
- Added Productions > Site Links.
- Common destinations can use a WordPress page or custom URL.
- Available destinations:
  Tickets, Registration, Participant Hub, Rehearsals, Costumes,
  Volunteer, Program Advertising, Sponsor Information, Venue & Parking, Contact.
- Production-specific links continue to take priority.
- Central links are automatic fallbacks across the homepage.

Sponsors:
- Homepage sponsors are grouped by sponsorship level.
- Supported levels:
  Presenting, Gold, Silver, Bronze, Community.
- Presenting sponsors receive a larger featured layout.
- Existing sponsor appearance settings remain active.

Admin:
- Custom post types no longer create duplicate top-level menu items.
- “Website” is now labeled “Homepage Builder.”
- Added a dedicated Site Links admin screen and dashboard shortcut.

This release preserves the existing 2.3.8 homepage layout and appearance.


VERSION 2.5.0 — PARTICIPANT HUB FOUNDATION

New shortcode:
[ifp_participant_hub]

Participant Hub:
- Compact current-production header
- Optional opening-night countdown
- Next rehearsal summary
- Quick links from Site Links
- Color-coded participant notices
- Notice start/end scheduling and sticky notices
- Timeline-style Important Dates
- Resources grouped automatically by type
- Featured resources
- WordPress-page or custom-URL resource destinations
- Password-required / staff-resource labels
- Production contact directory

New admin module:
Productions > Participant Hub

New content type:
Productions > Contacts

Participant Resources now support:
- WordPress Page
- Custom URL or file URL
- Resource group
- Button text
- Featured status
- Password-required label

Public access:
The Participant Hub remains public. For a limited internal resource, link it to a normal WordPress page and set that page to Password Protected using WordPress visibility controls.


VERSION 2.5.1 — LOGIN REDIRECT

Added under Productions > Settings > Admin Experience:

- Open the Productions Dashboard after login

Behavior:
- Disabled by default.
- When enabled, users who can edit production content are sent to:
  Productions > Dashboard
- Explicit WordPress redirect destinations are respected.
- Users without permission to access production content retain the normal WordPress login destination.


VERSION 2.5.2 — EDITABLE CONTACT CATEGORIES

Added under Productions > Participant Hub:
- Contact Categories editor
- Enter one category per line
- Contact editor dropdown updates automatically

Existing contacts:
- Existing saved categories are preserved.
- If a category is removed from settings but is still used by a contact,
  it remains available for that contact until a replacement is selected.


VERSION 2.5.3 — HOMEPAGE SECTION SPACING AND COLORS

Added under Productions > Homepage Builder > Homepage Section Layout:
- Desktop section spacing
- Mobile section spacing
- Join / Watch / Support background
- Production Story background
- Participant preview background
- Important Dates background and text color
- Sponsors background
- Past Productions background

The pale yellow shown behind the Production Story was the plugin's previous
hard-coded #fffaf2 background. It is now editable.


VERSION 2.6.0 — PRODUCTION RELATIONSHIPS
- Assign notices, resources, Important Dates, contacts, and sponsors to All Productions, Current Production, or a named production.
- Participant Hub, homepage dates, and homepage sponsors filter automatically for the current production.
- Existing unassigned content remains shared for backward compatibility.
- Admin lists show the assigned production.


V2.6.0 ADDITION — PATHWAY CARD HEIGHT

Added under Productions > Homepage Builder > Pathway Card Appearance:
- Card Minimum Height — Desktop
- Card Minimum Height — Mobile

The values use pixels and set a minimum height, so longer content can still expand without clipping.


VERSION 2.6.1 — PRODUCTION WIZARD

Added Productions > New Production.

The wizard creates:
- Production title, season, year, tagline, and introduction
- Opening and closing dates
- Accent and secondary colors
- Production-specific ticket, registration, Participant Hub, and volunteer links
- Optional opening and closing performance Important Dates
- Optional costume fitting, costume delivery, photo day, and dress rehearsal dates

Current-production workflow:
- The new production can be made current during creation.
- The prior current production remains published and becomes archived automatically.
- Starter Important Dates are permanently assigned to the new production.

After creation:
- The wizard links directly to the normal Production editor for artwork,
  show logo, venue details, and final review.


VERSION 2.6.2 — QUICK EDIT PRODUCTION ASSIGNMENT

The Production selector is now available in WordPress Quick Edit for:
- Notices
- Resources
- Important Dates
- Contacts
- Sponsors

The saved assignment is loaded automatically when Quick Edit opens.


VERSION 2.6.3 — BULK EDIT PRODUCTION ASSIGNMENT

The Production selector is now available in WordPress Bulk Edit for:
- Notices
- Resources
- Important Dates
- Contacts
- Sponsors

Bulk Edit defaults to “No Change,” so existing production assignments are
preserved unless a new assignment is intentionally selected.


VERSION 2.6.4 — RESOURCE DESTINATIONS

Participant Resources can now use:
- WordPress Page
- External Link
- Media Library File

Media files use the standard WordPress Media Library selector and display
their file extension and size when available.

The homepage resource preview now uses the same custom Button Text and
destination as the Participant Hub.


VERSION 2.6.5 — CONTENT DATES AND PAGE LAYOUT DETECTION

Notices and Resources:
- Optional Posted date on the Participant Hub
- Optional Updated date on the Participant Hub
- Separate toggles for notices and resources
- Published and Updated columns in WordPress admin
- Sortable Published and Updated columns

Page layout:
- Automatically marks pages containing Ice & Field Productions shortcodes
  with the Productions Layout.
- Adds a Page Layout selector to the WordPress Page editor:
  Automatic, Productions Layout, or Standard Page Layout.
- The theme can use the resulting body classes to hide page titles and
  remove generic page chrome without affecting standard pages.


VERSION 2.6.6 — PARTICIPANT HUB APPEARANCE

Added under Productions > Participant Hub:

Hero:
- Show logo position: Above, Left, or Right
- Optional current-production Featured Image background
- Background overlay color
- Background overlay opacity

Important Date card:
- Customizable eyebrow text
- Default changed to “Next Important Date”
- Card now displays the next upcoming featured Important Date of any type,
  rather than only Rehearsal and Dress Rehearsal entries

Corners:
- Main Participant Hub card corner radius
- Quick-link pill corner radius


VERSION 2.6.7 — PARTICIPANT HUB REFRESH

Rebuilt Productions > Participant Hub into organized collapsible sections:

- Hero
- Status Cards
- Quick Links
- Notices
- Resources
- Important Dates
- Contacts
- Global Appearance

New controls include:
- Logo position: Above, Left, Right, or Hidden
- Logo width
- Featured Image background
- Background position
- Overlay color and opacity
- Hero padding and corner radius
- Countdown eyebrow
- Next Important Date eyebrow
- Next-date selection mode
- Status card background and opacity
- Main card and quick-link corner radii
- Section spacing
- Content width

Existing notices, resources, contacts, links, dates, and public content remain intact.


VERSION 2.6.8 — HERO BACKGROUND AND LOGO LAYOUT FIX

- Featured Image background is now limited to the blue Participant Hub hero.
- Notices, Important Dates, Resources, and Contacts retain their normal backgrounds.
- Overlay color and opacity affect only the hero card.
- Right-positioned logos now sit as compact top-right branding rather than a competing full column.
- Left and Above logo layouts were also refined.
- Mobile logo layouts stack cleanly.


VERSION 2.6.9 — IDENTITY WIDTH AND LOGO POSITION FIX

- Removes the old 780px identity-container limit for Left and Right logo layouts.
- Keeps the text column itself capped at a readable 780px.
- Allows the logo to occupy the remaining hero width at the far side.
- Right-positioned logos now align against the right side of the hero.
- Left-positioned logos mirror the same behavior.
- Above and Hidden layouts retain the original readable identity width.


VERSION 2.7.0 — GROUPS FOUNDATION

Added Productions > Groups.

Each Group stores:
- Production
- Group type
- Coach / choreographer
- Performance assignments
- Rehearsal information
- Music and costume notes
- Internal / Participant Hub / Public visibility
- Featured image and description
- Manual participant roster
- Dash season, program, level, and event identifiers

Admin:
- Production and Group Type filters
- Participant-count and Dash-status columns
- Sortable Type and Participant columns
- Add/remove participant rows
- Groups & Cast dashboard card

Public:
- Added [ifp_groups]
- Optional production_id and visibility attributes
- Groups are hidden unless their visibility allows public display

This establishes the local data model for the upcoming Dash preview,
import, participant synchronization, export, and email tools.


VERSION 2.7.1 — DEDICATED PRODUCTION PAGES

“View Production” now opens a complete production-specific public page.

The plugin supplies its own single-production template containing:
- Featured-image hero
- Show logo or production title
- Season, year, tagline, and performance date range
- Production-specific opening-night countdown
- Tickets, registration, and Participant Hub buttons
- Production story
- Production-assigned Important Dates
- Public performance groups
- Venue and parking information
- Trailer and program links
- Production-assigned sponsors

The page uses the viewed production's own information, so archived shows
remain accurate after a newer production becomes current.


VERSION 2.7.2 — PRODUCTION PAGE HUB LAYOUT

The public production page has been rebuilt using the same visual language
as the Participant Hub.

Changes:
- Rounded, contained hero with production artwork
- Hub-style identity, status cards, countdown, and quick links
- Explicit text colors for every section and card
- Hub-style About, Important Dates, Groups, Venue, Media, and Sponsors sections
- Improved mobile layouts
- Removed dependence on inherited theme heading and text colors
- Preserved all Groups functionality from 2.7.0


VERSION 2.7.3 — PRODUCTION HERO IDENTITY & SECTION CONTROLS

- Restored the production title in the hero.
- Moved the show logo into the upper-right identity space, matching the Participant Hub.
- Kept the title, tagline, and dates in a readable left column.
- Added a Public Page tab to each Production.
- Added independent show/hide controls for About, Important Dates, Groups,
  Venue, Media, and Sponsors.
- Public production pages and Participant Hub pages continue to share a
  common visual language while showing different content.


VERSION 2.7.4 — PRODUCTION PAGE COLOR FIX

- Added production-page-specific typography protection.
- Prevents broad theme heading styles from turning section titles white.
- Explicitly sets readable colors for group names, venue names, body text,
  section headings, sponsor headings, and card content.
- Preserves white typography only inside the dark hero.


VERSION 2.7.5 — INLINE PRODUCTION COLOR FIX

- Moved critical production-page contrast rules into the rendered template.
- Rules now load after the theme and do not depend on cached external CSS.
- Uses fixed Ice & Field navy rather than a possibly light production color.
- Adds WebKit text-fill protection for themes using text-fill effects.
- Covers production section headings, group names, venue names, dates,
  sponsor headings, card body text, and labels.


VERSION 2.7.6 — ORANGE EYEBROWS & SPACING SYSTEM

- Standardized production-page eyebrow labels to Ice & Field orange (#FF8033).
- Applied the orange hierarchy to hero labels, section labels, card labels,
  group types, and sponsor level headings.
- Preserved navy section titles and dark readable body text.
- Normalized spacing between eyebrow labels, titles, cards, and sections.


VERSION 2.7.7 — SHARED DASH CONNECTOR INTEGRATION

- Detects the separate Ice & Field Dash Connector plugin.
- Adds Productions > Dash Integration.
- Shows connector status and setup guidance.
- Saves the initial discovery scope:
  Season 12, Program 10, Levels 36–42.
- Keeps imports manual and preview-first.
- Links directly to connector diagnostics and the Raw API Explorer.
- Does not duplicate or store Dash credentials inside Productions.


== 2.7.8 ==
* Adds Dash Team discovery filtered by season and optional league IDs.
* Adds lazy registered-customer roster previews and counts through the shared connector.
* Adds manual, duplicate-safe Team-to-Group import/update workflow.
* Stores Dash team, league, season, product, status, raw payload, and last-sync metadata.


== Version 2.9.1: Dash Customer Quick Links ==
- Made the Dash Customer ID in each Person's Dash Connection box a direct link to that customer profile in DaySmart Recreation.
- Customer profiles open in a new tab with secure external-link attributes.
- Preserved the existing green Dash linked status badge and last-sync information.

== Version 2.9.0: People ==
People are now first-class records shared across productions and groups. The People screen supports production/group filters, Communication Center shortcuts, CSV exports, editable profiles, visible Dash synchronization details, and per-Person communication history.

== Communication Center ==

Open Productions > Communication to compose a message. Email audiences are resolved from the existing Production > Division > Group > Person relationships; no separate address list is maintained.

1. Optionally load an Email Template.
2. Choose Entire Production, Division, Group, or Selected People.
3. Write the subject and HTML message in the visual editor.
4. Choose whether the message should also become a reusable template.
5. Select Review Email.
6. Verify every unique recipient address and any missing-address warning.
7. Confirm Send.

Each unique address receives a separate private copy. If the same Person belongs to several selected Groups—or multiple People share an address—the address receives only one copy. Global history appears under Productions > Communication > Communication History, while messages connected to a Person also appear on that Person's edit screen.

WordPress records whether it handed each copy to the configured website mail service. It cannot independently confirm inbox delivery, bounces, or opens. Version 3.7.1 adds guarded attachments, failed-handoff retries, and a provider-event integration point for compatible transactional-email services.

== Version 3.7.1 — Communication Center Completion ==

- Choose up to five safe Media Library attachments, limited to 10 MB each and 20 MB combined.
- Set reusable sender name, reply-to address, accent color, and footer text under Communication > Email Settings.
- Override the sender name and reply-to address for an individual message before review.
- Retry only failed mail-service handoffs from Communication History; successful recipients are never resent.
- Display delivery, bounce, and open events only when an authenticated compatible mail-provider integration records them through `IFP_Communications::record_provider_event()`.
