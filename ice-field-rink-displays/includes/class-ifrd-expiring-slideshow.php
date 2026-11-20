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
            $starts_at = sanitize_text_field((string) ($row['starts_at'] ?? ''));
            $expires_at = sanitize_text_field((string) ($row['expires_at'] ?? ''));
            if ($url === '') continue;
            foreach (array($starts_at, $expires_at) as $scheduled_at) {
                if ($scheduled_at === '') continue;
                $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $scheduled_at, $timezone);
                $errors = DateTimeImmutable::getLastErrors();
                if (!$date || (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d\TH:i') !== $scheduled_at) continue 2;
            }
            if ($starts_at !== '' && $expires_at !== '' && $expires_at <= $starts_at) continue;
            $clean[] = array(
                'url' => $url,
                'starts_at' => $starts_at,
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
            $starts = $row['starts_at'] === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $row['starts_at'], $timezone);
            $expires = $row['expires_at'] === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $row['expires_at'], $timezone);
            if ($expires && $expires <= $now) continue;
            $row['starts_timestamp'] = $starts ? $starts->getTimestamp() * 1000 : 0;
            $row['expires_timestamp'] = $expires ? $expires->getTimestamp() * 1000 : 0;
            $active[] = $row;
        }
        return $active;
    }

    public static function render_editor($option_name, $field_name, $rows, $timezone_name, $title = 'Image Slideshow') {
        $rows = array_values((array) $rows);
        if (!$rows) $rows[] = array('url' => '', 'starts_at' => '', 'expires_at' => '');
        ?>
        <section class="ifrd-slideshow-editor" data-next-index="<?php echo esc_attr(count($rows)); ?>">
            <h2><?php echo esc_html($title); ?></h2>
            <p>Add Media Library images with optional begin and stop times. Scheduled images become eligible automatically, and expired images disappear automatically. Times use <strong><?php echo esc_html($timezone_name); ?></strong>.</p>
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
        $url = (string) ($row['url'] ?? '');
        ?>
        <div class="ifrd-slideshow-row">
            <button type="button" class="ifrd-drag-handle" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</button>
            <div class="ifrd-slide-preview<?php echo $url === '' ? ' is-empty' : ''; ?>">
                <img src="<?php echo esc_url($url); ?>" alt="">
                <span>No image selected</span>
            </div>
            <div class="ifrd-slide-fields">
                <div class="ifrd-media-picker" data-media-types="image" data-media-title="Choose slideshow image" data-media-button="Use this image">
                    <label><strong>Image</strong><input class="large-text ifrd-media-url" name="<?php echo esc_attr($prefix); ?>[url]" value="<?php echo esc_attr($url); ?>" placeholder="Select an image or paste its URL"></label>
                    <p><button type="button" class="button ifrd-media-select">Choose Image</button> <button type="button" class="button ifrd-media-clear">Clear</button></p>
                </div>
                <div class="ifrd-slide-schedule">
                    <label><strong>Begin displaying</strong><input type="datetime-local" step="900" name="<?php echo esc_attr($prefix); ?>[starts_at]" value="<?php echo esc_attr((string) ($row['starts_at'] ?? '')); ?>"><span>Blank means immediately.</span></label>
                    <label><strong>Stop displaying</strong><input type="datetime-local" step="900" name="<?php echo esc_attr($prefix); ?>[expires_at]" value="<?php echo esc_attr((string) ($row['expires_at'] ?? '')); ?>"><span>Blank means no expiration.</span></label>
                </div>
                <div class="ifrd-slide-actions"><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[delete_after_expiry]" value="1" <?php checked(!empty($row['delete_after_expiry'])); ?>> Trash Media Library file 48 hours after expiration</label><button type="button" class="button-link-delete ifrd-slideshow-remove">Remove slide</button></div>
            </div>
        </div>
        <?php
    }

    public static function print_editor_script() { ?>
        <style>
        .ifrd-slideshow-editor{margin-top:24px;padding-top:8px;border-top:1px solid #dcdcde}.ifrd-slideshow-rows{display:grid;gap:14px;max-width:1100px}.ifrd-slideshow-row{position:relative;display:grid;grid-template-columns:220px minmax(0,1fr);gap:18px;padding:16px 16px 16px 48px;border:1px solid #c3c4c7;border-radius:10px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04)}.ifrd-slideshow-row.is-dragging{opacity:.45;border:2px dashed #087dbb}.ifrd-drag-handle{position:absolute;left:10px;top:50%;transform:translateY(-50%);width:28px;height:54px;margin:0;padding:0;border:0;border-radius:6px;background:#eef3f6;color:#064b75;font-size:22px;line-height:1;cursor:grab}.ifrd-drag-handle:active{cursor:grabbing}.ifrd-slide-preview{display:grid;place-items:center;align-self:start;width:220px;aspect-ratio:16/9;overflow:hidden;border-radius:7px;background:#071f33}.ifrd-slide-preview img{display:block;width:100%;height:100%;object-fit:contain}.ifrd-slide-preview span{display:none;color:#cbd5e1;font-weight:600}.ifrd-slide-preview.is-empty img{display:none}.ifrd-slide-preview.is-empty span{display:block}.ifrd-slide-fields{min-width:0}.ifrd-media-picker label,.ifrd-slide-schedule label{display:grid;gap:5px}.ifrd-media-picker p{margin:8px 0 12px}.ifrd-media-url{font-family:monospace;font-size:12px}.ifrd-slide-schedule{display:grid;grid-template-columns:repeat(2,minmax(210px,1fr));gap:12px}.ifrd-slide-schedule input{width:100%}.ifrd-slide-schedule span{color:#646970;font-size:12px}.ifrd-slide-actions{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-top:14px;padding-top:12px;border-top:1px solid #f0f0f1}@media(max-width:782px){.ifrd-slideshow-row{grid-template-columns:1fr;padding-left:46px}.ifrd-slide-preview{width:100%}.ifrd-slide-schedule{grid-template-columns:1fr}.ifrd-slide-actions{align-items:flex-start;flex-direction:column}}
        </style>
        <script>(function(){let dragged=null;function preview(row){const field=row.querySelector('.ifrd-media-url'),box=row.querySelector('.ifrd-slide-preview'),img=box&&box.querySelector('img'),url=field?field.value.trim():'';if(!box||!img)return;box.classList.toggle('is-empty',!url);img.src=url}document.addEventListener('input',function(e){if(e.target.matches('.ifrd-media-url'))preview(e.target.closest('.ifrd-slideshow-row'))});document.addEventListener('pointerdown',function(e){const handle=e.target.closest('.ifrd-drag-handle');if(handle)handle.closest('.ifrd-slideshow-row').draggable=true});document.addEventListener('dragstart',function(e){const row=e.target.closest('.ifrd-slideshow-row');if(!row||!row.draggable)return;dragged=row;row.classList.add('is-dragging');e.dataTransfer.effectAllowed='move'});document.addEventListener('dragover',function(e){const row=e.target.closest('.ifrd-slideshow-row');if(!dragged||!row||row===dragged)return;e.preventDefault();const box=row.getBoundingClientRect();row.parentNode.insertBefore(dragged,e.clientY<box.top+box.height/2?row:row.nextSibling)});document.addEventListener('dragend',function(){if(dragged){dragged.classList.remove('is-dragging');dragged.draggable=false;dragged=null}});document.addEventListener('click',function(e){const add=e.target.closest('.ifrd-slideshow-add'),remove=e.target.closest('.ifrd-slideshow-remove');if(add){e.preventDefault();const editor=add.closest('.ifrd-slideshow-editor'),rows=editor.querySelector('.ifrd-slideshow-rows'),i=Number(editor.dataset.nextIndex||0);rows.insertAdjacentHTML('beforeend',editor.querySelector('.ifrd-slideshow-template').innerHTML.replace(/__INDEX__/g,String(i)));editor.dataset.nextIndex=String(i+1)}if(remove){e.preventDefault();remove.closest('.ifrd-slideshow-row').remove()}})})();</script>
    <?php }

    public static function render($rows, $seconds, $class_name, $link = '', $transition = 'fade', $transition_seconds = 1) {
        $rows = array_values((array) $rows);
        if (!$rows) return '';
        $transition = in_array($transition, array('fade', 'slide', 'none', 'brand-wipe'), true) ? $transition : 'fade';
        $transition_seconds = min(5, max(0.1, floatval($transition_seconds)));
        $id = 'ifrd_slides_' . wp_generate_password(8, false, false);
        ob_start(); ?>
        <div id="<?php echo esc_attr($id); ?>" class="<?php echo esc_attr($class_name); ?> ifrd-expiring-slideshow ifrd-transition-<?php echo esc_attr($transition); ?>" style="--ifrd-slide-transition:<?php echo esc_attr($transition_seconds); ?>s">
            <?php foreach ($rows as $index => $row): ?>
                <?php if ($link): ?><a href="<?php echo esc_url($link); ?>" class="ifrd-slide" data-starts="<?php echo esc_attr($row['starts_timestamp']); ?>" data-expires="<?php echo esc_attr($row['expires_timestamp']); ?>"><?php else: ?><span class="ifrd-slide" data-starts="<?php echo esc_attr($row['starts_timestamp']); ?>" data-expires="<?php echo esc_attr($row['expires_timestamp']); ?>"><?php endif; ?>
                <img src="<?php echo esc_url($row['url']); ?>" alt="">
                <?php echo $link ? '</a>' : '</span>'; ?>
            <?php endforeach; ?>
            <?php if ($transition === 'brand-wipe') : ?><div class="ifrd-brand-wipe" aria-hidden="true"><i></i><i></i><i></i><i></i></div><?php endif; ?>
        </div>
        <style>#<?php echo esc_attr($id); ?> .ifrd-slide{opacity:0;visibility:hidden;transition:opacity var(--ifrd-slide-transition) ease,transform var(--ifrd-slide-transition) ease}#<?php echo esc_attr($id); ?> .ifrd-slide.is-active{opacity:1;visibility:visible}#<?php echo esc_attr($id); ?>.ifrd-transition-slide .ifrd-slide{transform:translateX(4%)}#<?php echo esc_attr($id); ?>.ifrd-transition-slide .ifrd-slide.is-active{transform:translateX(0)}#<?php echo esc_attr($id); ?>.ifrd-transition-none .ifrd-slide,#<?php echo esc_attr($id); ?>.ifrd-transition-brand-wipe .ifrd-slide{transition:none}#<?php echo esc_attr($id); ?> .ifrd-brand-wipe{position:absolute;z-index:8;inset:0;display:grid;grid-template-columns:repeat(4,1fr);pointer-events:none;transform:translate3d(100%,0,0);will-change:transform;backface-visibility:hidden}#<?php echo esc_attr($id); ?> .ifrd-brand-wipe i{display:block}</style>
        <script>(function(){const root=document.getElementById(<?php echo wp_json_encode($id); ?>);if(!root)return;let current=0,busy=false;const wipe=root.querySelector('.ifrd-brand-wipe'),bands=wipe?Array.from(wipe.children):[],colors=['#003b5c','#33b6ff','#ff8033','#ffc600'],wipeHalf=140;function eligible(){const now=Date.now();return Array.from(root.querySelectorAll('.ifrd-slide')).filter(function(s){const a=Number(s.dataset.starts||0),x=Number(s.dataset.expires||0);return(!a||a<=now)&&(!x||x>now)})}function activate(slide){root.querySelectorAll('.ifrd-slide').forEach(function(s){s.classList.toggle('is-active',s===slide)})}function prepare(){if(!wipe)return;const shuffled=colors.slice().sort(function(){return Math.random()-.5}),weights=Array.from({length:4},function(){return .6+Math.random()*.8}),total=weights.reduce(function(sum,value){return sum+value},0);wipe.style.gridTemplateColumns=weights.map(function(value){return(value/total*100).toFixed(2)+'%'}).join(' ');bands.forEach(function(band,index){band.style.background=shuffled[index]})}async function show(){const slides=eligible();if(busy||!slides.length){if(!slides.length)root.style.display='none';return}root.style.display='';current=current%slides.length;const next=slides[current],active=root.querySelector('.ifrd-slide.is-active');current++;if(!active||!wipe){activate(next);return}if(active===next&&slides.length===1)return;busy=true;prepare();let cover,uncover;try{cover=wipe.animate([{transform:'translate3d(100%,0,0)'},{transform:'translate3d(0,0,0)'}],{duration:wipeHalf,easing:'cubic-bezier(.2,.4,.6,.8)',fill:'forwards'});await cover.finished;activate(next);uncover=wipe.animate([{transform:'translate3d(0,0,0)'},{transform:'translate3d(-100%,0,0)'}],{duration:wipeHalf,easing:'cubic-bezier(.4,.2,.8,.6)',fill:'forwards'});await uncover.finished}finally{wipe.style.transform='translate3d(100%,0,0)';if(cover)cover.cancel();if(uncover)uncover.cancel();busy=false}}prepare();show();setInterval(show,<?php echo absint(max(2, intval($seconds))) * 1000; ?>)})();</script>
        <?php return ob_get_clean();
    }
}
