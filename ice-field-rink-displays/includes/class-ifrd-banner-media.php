<?php
/**
 * Shared Media Library helpers for the two display settings pages.
 */
class IFRD_Banner_Media {
    public static function is_video($url, $type = 'auto') {
        $type = sanitize_key((string) $type);

        if ($type === 'video') {
            return true;
        }

        if ($type === 'image') {
            return false;
        }

        $path = wp_parse_url((string) $url, PHP_URL_PATH);
        $filetype = wp_check_filetype((string) $path);

        if (!empty($filetype['type'])) {
            return strpos((string) $filetype['type'], 'video/') === 0;
        }

        $extension = strtolower((string) pathinfo((string) $path, PATHINFO_EXTENSION));
        return in_array($extension, array('mp4', 'm4v', 'webm', 'ogv', 'ogg', 'mov'), true);
    }

    public static function render_picker($option_name, $settings, $field_name, $field_id, $allowed_types = array('image'), $args = array()) {
        $allowed_types = array_values(array_intersect(array('image', 'video'), array_map('sanitize_key', (array) $allowed_types)));

        if (empty($allowed_types)) {
            $allowed_types = array('image');
        }

        $url = isset($settings[$field_name]) ? (string) $settings[$field_name] : '';
        $type_field = !empty($args['type_field']) ? sanitize_key($args['type_field']) : '';
        $type = $type_field && isset($settings[$type_field]) ? sanitize_key($settings[$type_field]) : 'auto';
        $title = !empty($args['title']) ? (string) $args['title'] : 'Choose media';
        $button_text = !empty($args['button_text']) ? (string) $args['button_text'] : 'Use this media';
        $placeholder = !empty($args['placeholder']) ? (string) $args['placeholder'] : 'Select media or paste its URL';
        $description = !empty($args['description']) ? (string) $args['description'] : '';

        if (!in_array($type, array('auto', 'image', 'video'), true)) {
            $type = 'auto';
        }
        ?>
        <div
            class="ifrd-media-picker"
            data-media-types="<?php echo esc_attr(implode(',', $allowed_types)); ?>"
            data-media-title="<?php echo esc_attr($title); ?>"
            data-media-button="<?php echo esc_attr($button_text); ?>"
        >
            <input
                id="<?php echo esc_attr($field_id); ?>"
                class="large-text ifrd-media-url"
                name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($field_name); ?>]"
                value="<?php echo esc_attr($url); ?>"
                placeholder="<?php echo esc_attr($placeholder); ?>"
            >
            <p>
                <button type="button" class="button ifrd-media-select">Choose from Media Library</button>
                <button type="button" class="button ifrd-media-clear">Clear</button>
            </p>
            <?php if ($type_field): ?>
                <label>
                    Media type:
                    <select class="ifrd-media-type" name="<?php echo esc_attr($option_name); ?>[<?php echo esc_attr($type_field); ?>]">
                        <option value="auto" <?php selected($type, 'auto'); ?>>Detect automatically</option>
                        <option value="image" <?php selected($type, 'image'); ?>>Image</option>
                        <option value="video" <?php selected($type, 'video'); ?>>Video</option>
                    </select>
                </label>
            <?php endif; ?>
            <?php if ($description !== ''): ?><p class="description"><?php echo esc_html($description); ?></p><?php endif; ?>
        </div>
        <?php
    }

    public static function print_picker_script() {
        ?>
        <script>
        (function(){
            document.addEventListener('click',function(event){
                const selectButton=event.target.closest('.ifrd-media-select');
                const clearButton=event.target.closest('.ifrd-media-clear');

                if(!selectButton&&!clearButton)return;

                event.preventDefault();
                const picker=(selectButton||clearButton).closest('.ifrd-media-picker');
                const urlField=picker.querySelector('.ifrd-media-url');
                const typeField=picker.querySelector('.ifrd-media-type');

                if(clearButton){
                    urlField.value='';
                    if(typeField)typeField.value='auto';
                    urlField.dispatchEvent(new Event('input',{bubbles:true}));
                    return;
                }

                if(!window.wp||!wp.media)return;

                const allowedTypes=(picker.dataset.mediaTypes||'image').split(',').filter(Boolean);
                const libraryType=allowedTypes.length===1?allowedTypes[0]:allowedTypes;

                const frame=wp.media({
                    title:picker.dataset.mediaTitle||'Choose media',
                    button:{text:picker.dataset.mediaButton||'Use this media'},
                    library:{type:libraryType},
                    multiple:false
                });

                frame.on('select',function(){
                    const attachment=frame.state().get('selection').first().toJSON();
                    urlField.value=attachment.url||'';
                    if(typeField)typeField.value=attachment.type==='video'?'video':'image';
                    urlField.dispatchEvent(new Event('input',{bubbles:true}));
                });

                frame.open();
            });
        })();
        </script>
        <?php
    }
}

