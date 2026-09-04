<?php
/** Image slideshow rows shared by the full-screen and schedule-banner displays. */
class IFRD_Expiring_Slideshow {
    public static function sanitize($rows, $timezone_name = '') {
        $timezone_name = $timezone_name ?: IFRD_Scheduled_Media::timezone_name();
        $timezone = new DateTimeZone($timezone_name);
        $clean = array();
        foreach ((array) $rows as $row) {
            if (!is_array($row)) continue;
            $url = esc_url_raw((string) ($row['url'] ?? ''));
            $expires_at = sanitize_text_field((string) ($row['expires_at'] ?? ''));
            if ($url === '') continue;
            if ($expires_at !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $expires_at, $timezone);
                $errors = DateTimeImmutable::getLastErrors();
                if (!$date || (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d\TH:i') !== $expires_at) continue;
            }
            $clean[] = array(
                'url' => $url,
                'expires_at' => $expires_at,
                'delete_after_expiry' => !empty($row['delete_after_expiry']) ? '1' : '0',
            );
        }
        return $clean;
    }

    public static function active($rows, $timezone_name = '') {
        $timezone_name = $timezone_name ?: IFRD_Scheduled_Media::timezone_name();
        $timezone = new DateTimeZone($timezone_name);
        $now = new DateTimeImmutable('now', $timezone);
        $active = array();
        foreach (self::sanitize($rows, $timezone_name) as $row) {
            $expires = $row['expires_at'] === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $row['expires_at'], $timezone);
            if ($expires && $expires <= $now) continue;
            $row['expires_timestamp'] = $expires ? $expires->getTimestamp() * 1000 : 0;
            $active[] = $row;
        }
        return $active;
    }

    public static function render_editor($option_name, $field_name, $rows, $timezone_name, $title = 'Image Slideshow') {
        $rows = array_values((array) $rows);
        if (!$rows) $rows[] = array('url' => '', 'expires_at' => '');
        ?>
        <section class="ifrd-slideshow-editor" data-next-index="<?php echo esc_attr(count($rows)); ?>">
            <h2><?php echo esc_html($title); ?></h2>
            <p>Add Media Library images and an optional expiration date and time. Expired images disappear automatically. Times use <strong><?php echo esc_html($timezone_name); ?></strong>.</p>
            <div class="ifrd-slideshow-rows">
                <?php foreach ($rows as $index => $row) self::render_row($option_name, $field_name, $index, $row); ?>
            </div>
            <p><button type="button" class="button ifrd-slideshow-add">Add Image</button></p>
            <template class="ifrd-slideshow-template"><?php self::render_row($option_name, $field_name, '__INDEX__', array()); ?></template>
        </section>
        <?php
    }

    private static function render_row($option_name, $field_name, $index, $row) {
        $prefix = $option_name . '[' . $field_name . '][' . $index . ']';
        ?>
        <div class="ifrd-slideshow-row">
            <div class="ifrd-media-picker" data-media-types="image" data-media-title="Choose slideshow image" data-media-button="Use this image">
                <input class="large-text ifrd-media-url" name="<?php echo esc_attr($prefix); ?>[url]" value="<?php echo esc_attr((string) ($row['url'] ?? '')); ?>" placeholder="Select an image or paste its URL">
                <button type="button" class="button ifrd-media-select">Choose Image</button>
            </div>
            <label><strong>Expires</strong><br><input type="datetime-local" step="900" name="<?php echo esc_attr($prefix); ?>[expires_at]" value="<?php echo esc_attr((string) ($row['expires_at'] ?? '')); ?>"></label>
            <div><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[delete_after_expiry]" value="1" <?php checked(!empty($row['delete_after_expiry'])); ?>> Trash file after 48 hours</label><br><button type="button" class="button-link-delete ifrd-slideshow-remove">Remove</button></div>
        </div>
        <?php
    }

    public static function print_editor_script() { ?>
        <style>.ifrd-slideshow-editor{margin-top:24px;padding-top:8px;border-top:1px solid #dcdcde}.ifrd-slideshow-row{display:grid;grid-template-columns:minmax(340px,1fr) 220px auto;gap:14px;align-items:end;margin:10px 0;padding:14px;border:1px solid #dcdcde;border-radius:8px;background:#fff}@media(max-width:782px){.ifrd-slideshow-row{grid-template-columns:1fr}}</style>
        <script>(function(){document.addEventListener('click',function(e){const add=e.target.closest('.ifrd-slideshow-add'),remove=e.target.closest('.ifrd-slideshow-remove');if(add){e.preventDefault();const editor=add.closest('.ifrd-slideshow-editor'),rows=editor.querySelector('.ifrd-slideshow-rows'),i=Number(editor.dataset.nextIndex||0);rows.insertAdjacentHTML('beforeend',editor.querySelector('.ifrd-slideshow-template').innerHTML.replace(/__INDEX__/g,String(i)));editor.dataset.nextIndex=String(i+1)}if(remove){e.preventDefault();remove.closest('.ifrd-slideshow-row').remove()}})})();</script>
    <?php }

    public static function render($rows, $seconds, $class_name, $link = '', $transition = 'fade', $transition_seconds = 1) {
        $rows = array_values((array) $rows);
        if (!$rows) return '';
        $transition = in_array($transition, array('fade', 'slide', 'none'), true) ? $transition : 'fade';
        $transition_seconds = min(5, max(0.1, floatval($transition_seconds)));
        $id = 'ifrd_slides_' . wp_generate_password(8, false, false);
        ob_start(); ?>
        <div id="<?php echo esc_attr($id); ?>" class="<?php echo esc_attr($class_name); ?> ifrd-expiring-slideshow ifrd-transition-<?php echo esc_attr($transition); ?>" style="--ifrd-slide-transition:<?php echo esc_attr($transition_seconds); ?>s">
            <?php foreach ($rows as $index => $row): ?>
                <?php if ($link): ?><a href="<?php echo esc_url($link); ?>" class="ifrd-slide<?php echo $index ? '' : ' is-active'; ?>" data-expires="<?php echo esc_attr($row['expires_timestamp']); ?>"><?php else: ?><span class="ifrd-slide<?php echo $index ? '' : ' is-active'; ?>" data-expires="<?php echo esc_attr($row['expires_timestamp']); ?>"><?php endif; ?>
                <img src="<?php echo esc_url($row['url']); ?>" alt="">
                <?php echo $link ? '</a>' : '</span>'; ?>
            <?php endforeach; ?>
        </div>
        <style>#<?php echo esc_attr($id); ?> .ifrd-slide{opacity:0;visibility:hidden;transition:opacity var(--ifrd-slide-transition) ease,transform var(--ifrd-slide-transition) ease}#<?php echo esc_attr($id); ?> .ifrd-slide.is-active{opacity:1;visibility:visible}#<?php echo esc_attr($id); ?>.ifrd-transition-slide .ifrd-slide{transform:translateX(4%)}#<?php echo esc_attr($id); ?>.ifrd-transition-slide .ifrd-slide.is-active{transform:translateX(0)}#<?php echo esc_attr($id); ?>.ifrd-transition-none .ifrd-slide{transition:none}</style>
        <script>(function(){const root=document.getElementById(<?php echo wp_json_encode($id); ?>);if(!root)return;let current=0;function show(){const slides=Array.from(root.querySelectorAll('.ifrd-slide')).filter(function(s){const x=Number(s.dataset.expires||0);if(x&&x<=Date.now()){s.remove();return false}return true});if(!slides.length){root.style.display='none';return}current=current%slides.length;slides.forEach(function(s,i){s.classList.toggle('is-active',i===current)});current++}show();setInterval(show,<?php echo absint(max(2, intval($seconds))) * 1000; ?>)})();</script>
        <?php return ob_get_clean();
    }
}
