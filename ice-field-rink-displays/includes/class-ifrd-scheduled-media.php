<?php
/**
 * Shared scheduled-media helpers for screen video and schedule banner changes.
 */
class IFRD_Scheduled_Media {
    public static function timezone_name($settings = null) {
        if (!is_array($settings)) {
            $settings = get_option(IFRD_Schedule_Display::OPTION, array());
        }

        $name = trim((string) ($settings['display_timezone'] ?? 'America/Chicago'));
        if ($name === '' || !in_array($name, timezone_identifiers_list(), true)) {
            $name = 'America/Chicago';
        }
        return $name;
    }

    public static function sanitize($rows, $timezone_name = '') {
        $clean = array();
        $timezone_name = $timezone_name !== '' ? $timezone_name : self::timezone_name();
        if (!in_array($timezone_name, timezone_identifiers_list(), true)) {
            $timezone_name = self::timezone_name();
        }
        $timezone = new DateTimeZone($timezone_name);

        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $starts_at = sanitize_text_field((string) ($row['starts_at'] ?? ''));
            $video_url = esc_url_raw((string) ($row['video_url'] ?? ''));
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $starts_at, $timezone);
            $errors = DateTimeImmutable::getLastErrors();

            if (
                $video_url === '' ||
                !$date ||
                (is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count']))) ||
                $date->format('Y-m-d\TH:i') !== $starts_at
            ) {
                continue;
            }

            $clean[$starts_at] = array(
                'starts_at' => $starts_at,
                'video_url' => $video_url,
                'delete_after_expiry' => !empty($row['delete_after_expiry']) ? '1' : '0',
            );
        }

        ksort($clean, SORT_STRING);

        return array_values($clean);
    }

    public static function effective($base_url, $base_type, $rows, $timezone_name = '') {
        $timezone_name = $timezone_name !== '' ? $timezone_name : self::timezone_name();
        if (!in_array($timezone_name, timezone_identifiers_list(), true)) {
            $timezone_name = self::timezone_name();
        }
        $timezone = new DateTimeZone($timezone_name);
        $now = new DateTimeImmutable('now', $timezone);
        $effective = array(
            'url' => (string) $base_url,
            'type' => sanitize_key((string) $base_type),
            'starts_at' => '',
        );

        foreach (self::sanitize($rows, $timezone_name) as $row) {
            $starts = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $row['starts_at'], $timezone);
            if ($starts && $starts <= $now) {
                $effective = array(
                    'url' => $row['video_url'],
                    'type' => 'video',
                    'starts_at' => $row['starts_at'],
                );
            }
        }

        $effective['token'] = md5($effective['url'] . '|' . $effective['type'] . '|' . $effective['starts_at']);
        return $effective;
    }

    public static function render_editor($option_name, $field_name, $rows, $timezone_name, $title) {
        $rows = array_values((array) $rows);
        if (empty($rows)) {
            $rows[] = array('starts_at' => '', 'video_url' => '');
        }
        ?>
        <section class="ifrd-scheduled-media" data-option="<?php echo esc_attr($option_name); ?>" data-field="<?php echo esc_attr($field_name); ?>">
            <h2><?php echo esc_html($title); ?></h2>
            <p>Each video becomes active at its scheduled date and time and remains active until the next scheduled video. Times use <strong><?php echo esc_html($timezone_name); ?></strong>.</p>
            <div class="ifrd-scheduled-media-rows" data-next-index="<?php echo esc_attr(count($rows)); ?>">
                <?php
                $now_key = (new DateTimeImmutable('now', new DateTimeZone($timezone_name)))->format('Y-m-d\TH:i');
                $active_index = -1;
                foreach ($rows as $candidate_index => $candidate) {
                    if (!empty($candidate['starts_at']) && $candidate['starts_at'] <= $now_key) $active_index = $candidate_index;
                }
                foreach ($rows as $index => $row):
                    $status = empty($row['starts_at']) ? 'Draft' : ($index === $active_index ? 'Active' : ($row['starts_at'] > $now_key ? 'Upcoming' : 'Past'));
                ?>
                    <?php self::render_row($option_name, $field_name, $index, $row, $status); ?>
                <?php endforeach; ?>
            </div>
            <p><button type="button" class="button ifrd-scheduled-media-add">Add Scheduled Video</button></p>
            <template class="ifrd-scheduled-media-template"><?php self::render_row($option_name, $field_name, '__INDEX__', array()); ?></template>
        </section>
        <?php
    }

    private static function render_row($option_name, $field_name, $index, $row, $status = 'Draft') {
        $prefix = $option_name . '[' . $field_name . '][' . $index . ']';
        ?>
        <div class="ifrd-scheduled-media-row">
            <label>
                <strong>Switch date and time</strong> <span class="ifrd-scheduled-status ifrd-scheduled-status-<?php echo esc_attr(strtolower($status)); ?>"><?php echo esc_html($status); ?></span><br>
                <input type="datetime-local" step="900" name="<?php echo esc_attr($prefix); ?>[starts_at]" value="<?php echo esc_attr((string) ($row['starts_at'] ?? '')); ?>">
            </label>
            <div class="ifrd-media-picker" data-media-types="video" data-media-title="Choose scheduled video" data-media-button="Use this video">
                <input class="large-text ifrd-media-url" name="<?php echo esc_attr($prefix); ?>[video_url]" value="<?php echo esc_attr((string) ($row['video_url'] ?? '')); ?>" placeholder="Select a video or paste its URL">
                <p>
                    <button type="button" class="button ifrd-media-select">Choose from Media Library</button>
                    <button type="button" class="button ifrd-media-clear">Clear</button>
                    <button type="button" class="button-link-delete ifrd-scheduled-media-remove">Remove scheduled video</button>
                </p>
                <label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[delete_after_expiry]" value="1" <?php checked(!empty($row['delete_after_expiry'])); ?>> Move this Media Library file to Trash 48 hours after the next scheduled video replaces it</label>
            </div>
        </div>
        <?php
    }

    public static function print_editor_script() {
        ?>
        <style>
            .ifrd-scheduled-media{margin-top:28px;padding-top:8px;border-top:1px solid #dcdcde}.ifrd-scheduled-media-row{display:grid;grid-template-columns:minmax(210px,280px) minmax(360px,1fr);gap:18px;align-items:start;margin:12px 0;padding:16px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.ifrd-scheduled-media-row input[type="datetime-local"]{width:100%;margin-top:6px}.ifrd-scheduled-status{display:inline-block;margin-left:6px;padding:2px 7px;border-radius:999px;background:#f0f0f1;color:#50575e;font-size:11px}.ifrd-scheduled-status-active{background:#d7f1df;color:#0a5c2d}.ifrd-scheduled-status-upcoming{background:#dbeafe;color:#174ea6}.ifrd-scheduled-status-past{background:#f0f0f1;color:#646970}@media(max-width:782px){.ifrd-scheduled-media-row{grid-template-columns:1fr}}
        </style>
        <script>
        (function(){
            document.addEventListener('click',function(event){
                const addButton=event.target.closest('.ifrd-scheduled-media-add');
                const removeButton=event.target.closest('.ifrd-scheduled-media-remove');
                if(addButton){
                    event.preventDefault();
                    const editor=addButton.closest('.ifrd-scheduled-media');
                    const rows=editor.querySelector('.ifrd-scheduled-media-rows');
                    const template=editor.querySelector('.ifrd-scheduled-media-template');
                    const index=Number(rows.dataset.nextIndex||0);
                    rows.insertAdjacentHTML('beforeend',template.innerHTML.replace(/__INDEX__/g,String(index)));
                    rows.dataset.nextIndex=String(index+1);
                }
                if(removeButton){
                    event.preventDefault();
                    const row=removeButton.closest('.ifrd-scheduled-media-row');
                    if(row)row.remove();
                }
            });
        })();
        </script>
        <?php
    }
}
