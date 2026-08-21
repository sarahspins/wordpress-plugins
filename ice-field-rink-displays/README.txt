Ice & Field Rink Displays v2.7.7

Version 2.7.7 adds the monorepo Update URI and participates in the Dash Connector-managed private release updater.

Built from the v2.2.1 master provided by Sarah.

Shared Dash Connector integration:
- Requires Ice & Field Dash Connector v1.3.0 or newer.
- All OAuth tokens, Dash credentials, company selection, and API requests are handled by the connector plugin.
- Removes Client ID, Client Secret, and Company fields from both display settings pages.
- Adds connector status and configuration links to both display settings pages.
- Schedule and participant displays return a clear error if the connector is unavailable or not configured.
- Existing display settings, styling, shortcodes, schedule behavior, and participant behavior are preserved.

Display administration:
- Moves display administration out of the WordPress Settings menu.
- Adds a top-level Displays menu.
- Provides Schedule Display, Participants Display, and Shortcodes submenu pages.
- Allows Editor users to access and remotely refresh Schedule Display, while limiting their editable setting to Banner Media only.
- Allows Editor users to access and update Video for Screens; other display administration remains restricted to administrators.
- Removes "Rink" from the admin page and submenu names while preserving the existing shortcodes.
- Adds an in-dashboard shortcode reference with examples, supported attributes, defaults, configuration links, and usage notes.

Surgical schedule-only patch:
- Adds Display Time Zone setting, default America/Chicago.
- Parses Dash schedule event start/end times in the selected timezone.
- Calculates today/now using the selected timezone.
- Formats visible schedule event labels in the selected timezone.
- Bumps the schedule cache key to avoid stale GMT payloads.

Not otherwise changed:
- Participant display behavior
- CSS/layout
- Banner/logo display
- Locker room handling
- Learn to Skate grouping
- Simultaneous event grouping

Schedule metadata addition:
- Qualifying participant-display events show a registrant total such as "13 registered skaters" or "1 registered skater".
- When a qualifying event reaches its positive Dash registration capacity, the schedule prefixes the count with configurable full-session wording, defaulting to "FULL -".
- Administrators can change or disable that wording under Schedule Display; Editors remain limited to Banner Media.
- Qualifying events show both their registrant total and locker-room assignments while preserving short public notes.
- Locker-room assignments display on their own line beneath notes and registrant totals.
- Qualifying keywords and the registrants endpoint come from the existing participant display settings.

Banner media:
- Schedule and participant banner settings can select an image or video from the WordPress Media Library.
- Schedule and participant logos now use the WordPress Media Library picker.
- The participant fallback video now uses the WordPress Media Library picker.
- Manual media URLs remain supported.
- Video banners autoplay muted, loop continuously, and use inline playback.
- An explicit media-type selector is available when automatic detection is not suitable.

Schedule refresh reliability:
- Keeps the current schedule visible while a refresh is in progress.
- Keeps the last successful schedule visible if a refresh fails.
- Restores the last successful same-day schedule immediately after a browser reload while fresh data loads.

Public schedule calendar:
- Adds [rink_schedule_calendar] with a compact Day view and a weekly time-grid view.
- [rink_schedule_list] is provided as an alias.
- Week view places Gold and Silver in separate lanes inside every day.
- Date, rink, Day/Week, Today, previous-week, and next-week controls are included.
- Simultaneous events on the same rink are combined into one block.
- Daily and weekly views share one server-cached weekly payload.
- The current and next week are warmed every 15 minutes through WordPress cron.
- A two-day stale copy is retained, shown immediately after the fresh cache expires, and refreshed in the background.
- The browser keeps the current schedule visible while refreshed data is loading.
- Weekly blocks no longer repeat the rink name or exact time; both remain available in the hover/focus tooltip.
- Taller blocks display additional event-title lines, and the weekly grid gives each day more horizontal room.
- Adds a Session Type selector populated with useful categories actually present in the selected week, rather than literal event titles.
- Weekly and daily calendar views omit sessions that end at or before 5:00 AM.
- The weekly grid consistently begins at 5:00 AM, leaving breathing room above the first common 6:00 AM sessions.
- Uses event or event-type colors supplied by Dash when available, cached for 12 hours through the shared connector.
- Falls back to stable category colors matching the internal calendar (Freestyle, Stick & Puck, Public, Hockey, Specialty, and Camp).
- Gold and Silver are identified by softly tinted lane backgrounds instead of event-block colors.
- Adds a Calendar Event Color Strength setting, defaulting to 50%, that mutes both Dash-provided and fallback event colors without fading the text.
- Calendar events use an explicit Dash registration URL when present and can resolve the public sign-up route from a related team or program level when the events feed omits the URL.
- Related resources that explicitly report online registration as closed or disabled are not linked.
- Multiple distinct registration links on one combined calendar block open a registration-choice dialog.
- A Learn to Skate/Large Group parent absorbs every overlapping child-class block in that rink lane, producing one clean Learn to Skate block.

Preloaded server cache:
- The current week's warmed server snapshot is embedded with the shortcode so the calendar can render immediately without waiting for its first AJAX request.
- Current and next-week snapshots are force-refreshed at local midnight and noon using WordPress's twice-daily scheduled task.
- Existing stale-while-refreshing behavior remains in place, so visitors continue seeing the last successful schedule while a background refresh runs.
- Generated registration links now require a positive open-registration signal from the related team/program and respect closed/full/date-window states.
- Schedule Display settings now include a Calendar Registration Links checkbox. Disabling it makes all day/week events display-only and skips registration-link API lookups.
- Schedule Display settings now include a Calendar Session Type Selector checkbox so the public category filter can be hidden completely.
- The daily calendar now uses a shared time grid with aligned Gold and Silver lanes. Event-block height and position reflect actual start/end times, matching the weekly calendar behavior.

Video for Screens:
- Adds Displays -> Video for Screens with a WordPress Media Library video picker.
- Adds [video_for_screens] for a full-browser, muted, continuously looping video.
- Saving a different video signals open screen pages to refresh.
- Adds a Refresh Screens Now button for remotely reloading open video displays within about 30 seconds.
- Adds LG webOS browser-specific video positioning and explicit viewport sizing to prevent one-sided black bars and offset fullscreen playback.
- Automatically sends a one-time remote refresh signal after a plugin version update, while retaining the manual Refresh Screens Now control.

Additional video pages:
- Adds Displays -> Pricing Page with its own independently selected full-screen video and [pricing_page] shortcode.
- Adds Displays -> Public Skating Rules Page with its own independently selected full-screen video and [public_skating_rules_page] shortcode.
- Both pages are available to Editor users and include independent automatic and manual remote refresh controls.
- Both reuse the LG webOS fullscreen compatibility behavior from Video for Screens.

Schedule banner remote update:
- Adds an Update Video Now button under Displays -> Schedule Display.
- Open [rink_schedule_display] pages check for remote-update requests every 30 seconds and reload automatically.
- Saving different schedule banner media also signals open schedule screens to reload.
- The page reload preserves the existing last-successful schedule behavior while the fresh schedule request completes.
