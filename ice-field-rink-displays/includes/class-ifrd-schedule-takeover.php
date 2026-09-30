<?php
/** Scheduled full-screen replacements for the normal schedule display. */
class IFRD_Schedule_Takeover {
    public static function sanitize($rows, $timezone_name = '') {
        $timezone_name = $timezone_name !== '' ? $timezone_name : IFRD_Scheduled_Media::timezone_name();
        if (!in_array($timezone_name, timezone_identifiers_list(), true)) {
            $timezone_name = IFRD_Scheduled_Media::timezone_name();
        }
        $timezone = new DateTimeZone($timezone_name);
        $clean = array();

        foreach ((array) $rows as $row) {
            if (!is_array($row)) continue;

            $starts_at = sanitize_text_field((string) ($row['starts_at'] ?? ''));
            $ends_at = sanitize_text_field((string) ($row['ends_at'] ?? ''));
            $source = (($row['source'] ?? '') === 'custom_video') ? 'custom_video' : 'video_for_screens';
            $video_url = $source === 'custom_video' ? esc_url_raw((string) ($row['video_url'] ?? '')) : '';
            $starts = self::parse_local($starts_at, $timezone);
            $ends = self::parse_local($ends_at, $timezone);

            if (!$starts || !$ends || $ends <= $starts || ($source === 'custom_video' && $video_url === '')) continue;

            $clean[$starts_at] = array(
                'starts_at' => $starts_at,
                'ends_at' => $ends_at,
                'source' => $source,
                'video_url' => $video_url,
            );
        }

        ksort($clean, SORT_STRING);
        return array_values($clean);
    }

    public static function effective($rows, $timezone_name = '', $now = null) {
        $timezone_name = $timezone_name !== '' ? $timezone_name : IFRD_Scheduled_Media::timezone_name();
        if (!in_array($timezone_name, timezone_identifiers_list(), true)) {
            $timezone_name = IFRD_Scheduled_Media::timezone_name();
        }
        $timezone = new DateTimeZone($timezone_name);
        if (!$now instanceof DateTimeImmutable) $now = new DateTimeImmutable('now', $timezone);
        else $now = $now->setTimezone($timezone);

        $effective = array(
            'active' => false,
            'source' => '',
            'video_url' => '',
            'starts_at' => '',
            'ends_at' => '',
        );

        foreach (self::sanitize($rows, $timezone_name) as $row) {
            $starts = self::parse_local($row['starts_at'], $timezone);
            $ends = self::parse_local($row['ends_at'], $timezone);
            if ($starts && $ends && $starts <= $now && $now < $ends) {
                $effective = array_merge(array('active' => true), $row);
            }
        }

        $effective['token'] = md5(implode('|', array(
            $effective['active'] ? '1' : '0',
            $effective['source'],
            $effective['video_url'],
            $effective['starts_at'],
            $effective['ends_at'],
        )));
        return $effective;
    }

    public static function next_transition($rows, $timezone_name = '') {
        $timezone_name = $timezone_name !== '' ? $timezone_name : IFRD_Scheduled_Media::timezone_name();
        if (!in_array($timezone_name, timezone_identifiers_list(), true)) {
            $timezone_name = IFRD_Scheduled_Media::timezone_name();
        }
        $timezone = new DateTimeZone($timezone_name);
        $now = new DateTimeImmutable('now', $timezone);
        $next = null;

        foreach (self::sanitize($rows, $timezone_name) as $row) {
            foreach (array('starts_at', 'ends_at') as $key) {
                $candidate = self::parse_local($row[$key], $timezone);
                if ($candidate && $candidate > $now && (!$next || $candidate < $next)) $next = $candidate;
            }
        }

        return $next ? $next->format('Y-m-d\TH:i') : '';
    }

    private static function parse_local($value, $timezone) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', (string) $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count'])))) return null;
        return $date->format('Y-m-d\TH:i') === $value ? $date : null;
    }

    public static function render_editor($option_name, $field_name, $rows, $timezone_name) {
        $rows = array_values((array) $rows);
        if (!$rows) $rows[] = array();
        $now_key = (new DateTimeImmutable('now', new DateTimeZone($timezone_name)))->format('Y-m-d\TH:i');
        ?>
        <section class="ifrd-takeovers" data-option="<?php echo esc_attr($option_name); ?>" data-field="<?php echo esc_attr($field_name); ?>">
            <h2>Scheduled Schedule Display Takeovers</h2>
            <p>Temporarily replace the normal rink schedule with the current Video for Screens content or a separate video. The schedule returns automatically at the end time. Times use <strong><?php echo esc_html($timezone_name); ?></strong>.</p>
            <div class="ifrd-takeover-rows" data-next-index="<?php echo esc_attr(count($rows)); ?>">
                <?php foreach ($rows as $index => $row):
                    $status = 'Draft';
                    if (!empty($row['starts_at']) && !empty($row['ends_at'])) {
                        $status = $row['ends_at'] <= $now_key ? 'Past' : ($row['starts_at'] <= $now_key ? 'Active' : 'Upcoming');
                    }
                    self::render_row($option_name, $field_name, $index, $row, $status);
                endforeach; ?>
            </div>
            <p><button type="button" class="button ifrd-takeover-add">Add Schedule Takeover</button></p>
            <p class="description">If time windows overlap, the takeover with the latest start time is shown.</p>
            <template class="ifrd-takeover-template"><?php self::render_row($option_name, $field_name, '__INDEX__', array()); ?></template>
        </section>
        <?php
    }

    private static function render_row($option_name, $field_name, $index, $row, $status = 'Draft') {
        $prefix = $option_name . '[' . $field_name . '][' . $index . ']';
        $source = (($row['source'] ?? '') === 'custom_video') ? 'custom_video' : 'video_for_screens';
        ?>
        <div class="ifrd-takeover-row">
            <div class="ifrd-takeover-times">
                <strong>Display window</strong> <span class="ifrd-takeover-status ifrd-takeover-status-<?php echo esc_attr(strtolower($status)); ?>"><?php echo esc_html($status); ?></span><br>
                <label>Start<br><input type="datetime-local" step="900" name="<?php echo esc_attr($prefix); ?>[starts_at]" value="<?php echo esc_attr((string) ($row['starts_at'] ?? '')); ?>"></label>
                <label>End<br><input type="datetime-local" step="900" name="<?php echo esc_attr($prefix); ?>[ends_at]" value="<?php echo esc_attr((string) ($row['ends_at'] ?? '')); ?>"></label>
            </div>
            <div>
                <label><strong>Show</strong><br>
                    <select class="ifrd-takeover-source" name="<?php echo esc_attr($prefix); ?>[source]">
                        <option value="video_for_screens" <?php selected($source, 'video_for_screens'); ?>>Current Video for Screens content</option>
                        <option value="custom_video" <?php selected($source, 'custom_video'); ?>>A separate video</option>
                    </select>
                </label>
                <div class="ifrd-media-picker ifrd-takeover-custom-video" data-media-types="video" data-media-title="Choose takeover video" data-media-button="Use this video">
                    <input class="large-text ifrd-media-url" name="<?php echo esc_attr($prefix); ?>[video_url]" value="<?php echo esc_attr((string) ($row['video_url'] ?? '')); ?>" placeholder="Select a video or paste its URL">
                    <p><button type="button" class="button ifrd-media-select">Choose from Media Library</button> <button type="button" class="button ifrd-media-clear">Clear</button></p>
                </div>
                <p><button type="button" class="button-link-delete ifrd-takeover-remove">Remove takeover</button></p>
            </div>
        </div>
        <?php
    }

    public static function print_editor_script() {
        ?>
        <style>
            .ifrd-takeovers{margin-top:28px;padding-top:8px;border-top:1px solid #dcdcde}.ifrd-takeover-row{display:grid;grid-template-columns:minmax(250px,330px) minmax(360px,1fr);gap:18px;align-items:start;margin:12px 0;padding:16px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.ifrd-takeover-times label{display:block;margin-top:8px}.ifrd-takeover-times input{width:100%}.ifrd-takeover-status{display:inline-block;margin-left:6px;padding:2px 7px;border-radius:999px;background:#f0f0f1;color:#50575e;font-size:11px}.ifrd-takeover-status-active{background:#d7f1df;color:#0a5c2d}.ifrd-takeover-status-upcoming{background:#dbeafe;color:#174ea6}.ifrd-takeover-custom-video{margin-top:12px}.ifrd-takeover-row[data-source="video_for_screens"] .ifrd-takeover-custom-video{display:none}@media(max-width:782px){.ifrd-takeover-row{grid-template-columns:1fr}}
        </style>
        <script>
        (function(){
            function update(row){const select=row.querySelector('.ifrd-takeover-source');row.dataset.source=select?select.value:'video_for_screens';}
            document.querySelectorAll('.ifrd-takeover-row').forEach(update);
            document.addEventListener('change',function(event){if(event.target.matches('.ifrd-takeover-source'))update(event.target.closest('.ifrd-takeover-row'));});
            document.addEventListener('click',function(event){
                const add=event.target.closest('.ifrd-takeover-add');
                const remove=event.target.closest('.ifrd-takeover-remove');
                if(add){event.preventDefault();const editor=add.closest('.ifrd-takeovers');const rows=editor.querySelector('.ifrd-takeover-rows');const template=editor.querySelector('.ifrd-takeover-template');const index=Number(rows.dataset.nextIndex||0);rows.insertAdjacentHTML('beforeend',template.innerHTML.replace(/__INDEX__/g,String(index)));rows.dataset.nextIndex=String(index+1);update(rows.lastElementChild);}
                if(remove){event.preventDefault();const row=remove.closest('.ifrd-takeover-row');if(row)row.remove();}
            });
        })();
        </script>
        <?php
    }
}
