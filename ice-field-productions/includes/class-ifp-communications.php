<?php
if (!defined('ABSPATH')) exit;

/**
 * HTML email composition, recipient resolution, templates, and local history.
 *
 * Version 3.1.0 deliberately records whether WordPress handed each message to
 * its configured mail service. Delivery, bounce, and open tracking remain a
 * later milestone because wp_mail() cannot verify those outcomes by itself.
 */
class IFP_Communications {
    const COMMUNICATION_TYPE = 'ifp_communication';
    const TEMPLATE_TYPE = 'ifp_email_template';
    const PERSON_HISTORY_KEY = '_ifp_communication_ids';
    const MAX_RECIPIENTS = 300;

    public static function init() {
        add_action('init', [__CLASS__, 'register_post_types']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('admin_post_ifp_send_communication', [__CLASS__, 'send_action']);
        add_action('add_meta_boxes', [__CLASS__, 'meta_boxes']);
        add_filter('use_block_editor_for_post_type', [__CLASS__, 'classic_template_editor'], 10, 2);
        add_filter('wp_default_editor', [__CLASS__, 'default_visual_editor']);
        add_filter('enter_title_here', [__CLASS__, 'template_title_placeholder'], 10, 2);
        add_filter('post_row_actions', [__CLASS__, 'template_row_actions'], 10, 2);
        add_action('edit_form_after_title', [__CLASS__, 'template_editor_help']);
    }

    public static function register_post_types() {
        register_post_type(self::COMMUNICATION_TYPE, [
            'labels' => [
                'name' => 'Communication History',
                'singular_name' => 'Communication',
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title','editor','author'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_post_type(self::TEMPLATE_TYPE, [
            'labels' => [
                'name' => 'Email Templates',
                'singular_name' => 'Email Template',
                'add_new' => 'Add Template',
                'add_new_item' => 'Add Email Template',
                'edit_item' => 'Edit Email Template',
                'new_item' => 'New Email Template',
                'search_items' => 'Search Email Templates',
                'not_found' => 'No email templates found',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => ['title','editor','revisions'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public static function admin_assets($hook) {
        $screen = get_current_screen();
        $is_related_editor = $screen && in_array($screen->post_type, ['ifp_participant', self::TEMPLATE_TYPE], true);
        if (strpos((string) $hook, 'ifp-communications') === false && !$is_related_editor) return;

        wp_enqueue_style('ifp-admin', IFP_URL . 'assets/admin.css', [], IFP_VERSION);
        if (strpos((string) $hook, 'ifp-communications') === false) return;
        wp_enqueue_script(
            'ifp-communications',
            IFP_URL . 'assets/communications.js',
            ['jquery'],
            IFP_VERSION,
            true
        );
    }

    public static function classic_template_editor($use_block_editor, $post_type) {
        return $post_type === self::TEMPLATE_TYPE ? false : $use_block_editor;
    }

    public static function default_visual_editor($editor) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && $screen->post_type === self::TEMPLATE_TYPE ? 'tinymce' : $editor;
    }

    public static function template_title_placeholder($placeholder, $post) {
        return $post && $post->post_type === self::TEMPLATE_TYPE
            ? 'Email subject and template name'
            : $placeholder;
    }

    public static function template_editor_help($post) {
        if (!$post || $post->post_type !== self::TEMPLATE_TYPE) return;
        echo '<div class="notice notice-info inline"><p>The title becomes the email subject. Write the reusable HTML message in the visual editor below, then publish the template so it appears in Communication Center.</p></div>';
    }

    public static function template_row_actions($actions, $post) {
        if (!$post || $post->post_type !== self::TEMPLATE_TYPE) return $actions;
        $url = add_query_arg([
            'page' => 'ifp-communications',
            'template_id' => $post->ID,
        ], admin_url('admin.php'));
        $actions['ifp_use_template'] = '<a href="' . esc_url($url) . '">Use in Email</a>';
        return $actions;
    }

    public static function meta_boxes() {
        add_meta_box(
            'ifp_person_communication_history',
            'Communication History',
            [__CLASS__, 'person_history_box'],
            'ifp_participant',
            'normal',
            'default'
        );

        foreach (['ifp_production','ifp_division'] as $post_type) {
            add_meta_box(
                'ifp_communication_shortcut',
                'Communication',
                [__CLASS__, 'communication_shortcut_box'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public static function communication_shortcut_box($post) {
        $recipient_type = $post->post_type === 'ifp_division' ? 'division' : 'production';
        $url = self::compose_url($recipient_type, $post->ID);
        echo '<p>Email every Person with a valid address in this ' . esc_html(ucfirst($recipient_type)) . '.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($url) . '">Compose Email</a></p>';
        echo '<p class="description">Recipients are reviewed before anything is sent.</p>';
    }

    public static function person_history_box($post) {
        $communication_ids = array_values(array_unique(array_filter(array_map(
            'absint',
            (array) get_post_meta($post->ID, self::PERSON_HISTORY_KEY, true)
        ))));
        $communication_ids = array_slice(array_reverse($communication_ids), 0, 10);
        $compose_url = self::compose_url('people', $post->ID);
        $email = sanitize_email((string) get_post_meta($post->ID, '_ifp_participant_email', true));

        echo '<p>';
        if ($email) echo '<a class="button button-primary" href="' . esc_url($compose_url) . '">Email This Person</a> ';
        else echo '<span class="button disabled">Email This Person</span> ';
        echo '<a class="button" href="' . esc_url(self::history_url()) . '">All Communication</a></p>';
        if (!$email) echo '<p class="description">Add an email address to this Person before composing a message.</p>';

        if (!$communication_ids) {
            echo '<p class="description">No messages have been recorded for this Person yet.</p>';
            return;
        }

        echo '<div class="ifp-person-communication-list">';
        foreach ($communication_ids as $communication_id) {
            $communication = get_post($communication_id);
            if (!$communication || $communication->post_type !== self::COMMUNICATION_TYPE) continue;
            $status = self::person_result($communication_id, $post->ID);
            $sent_at = (string) get_post_meta($communication_id, '_ifp_comm_sent_at', true);
            echo '<div class="ifp-person-communication">';
            echo '<strong><a href="' . esc_url(self::history_url($communication_id)) . '">' . esc_html($communication->post_title) . '</a></strong>';
            echo '<span>' . esc_html(self::admin_datetime($sent_at)) . '</span>';
            echo '<span class="ifp-email-result ifp-email-result--' . esc_attr($status) . '">' . esc_html($status === 'sent' ? 'Handed to mail service' : 'Send failed') . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }

    public static function compose_url($recipient_type = '', $target_id = 0) {
        $args = ['page' => 'ifp-communications'];
        $recipient_type = sanitize_key((string) $recipient_type);
        if ($recipient_type !== '') $args['recipient_type'] = $recipient_type;

        $target_id = absint($target_id);
        if ($target_id) {
            if ($recipient_type === 'production') $args['production_id'] = $target_id;
            if ($recipient_type === 'division') $args['division_id'] = $target_id;
            if ($recipient_type === 'group') $args['group_id'] = $target_id;
            if ($recipient_type === 'people') $args['person_ids'] = [$target_id];
        }
        return add_query_arg($args, admin_url('admin.php'));
    }

    public static function history_url($communication_id = 0) {
        $args = ['page' => 'ifp-communications', 'view' => 'history'];
        if ($communication_id) $args['communication_id'] = absint($communication_id);
        return add_query_arg($args, admin_url('admin.php'));
    }

    public static function page() {
        if (!current_user_can('edit_ifp_people')) {
            wp_die('You do not have permission to use the Communication Center.');
        }

        $view = sanitize_key((string) wp_unslash($_GET['view'] ?? 'compose'));
        if ($view === 'history') {
            self::render_history_page();
            return;
        }

        $prepared = null;
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ifp_return_to_compose'])) {
            check_admin_referer('ifp_return_to_compose', 'ifp_return_nonce');
            $return_token = sanitize_key((string) wp_unslash($_POST['ifp_preview_token'] ?? ''));
            if ($return_token !== '') delete_transient(self::preview_key($return_token));
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ifp_prepare_communication'])) {
            check_admin_referer('ifp_prepare_communication', 'ifp_communication_nonce');
            $prepared = self::prepare_preview();
            if (is_wp_error($prepared)) {
                $error = $prepared;
                $prepared = null;
            }
        }

        self::render_header('Compose Email', 'Write an HTML email, choose its audience, and review every unique address before sending.');
        self::render_notice();
        if ($error) echo '<div class="notice notice-error"><p>' . esc_html($error->get_error_message()) . '</p></div>';

        if ($prepared) self::render_review($prepared);
        else self::render_compose();

        echo '</div>';
    }

    private static function render_header($title, $lead) {
        ?>
        <div class="wrap ifp-dashboard ifp-communications">
            <div class="ifp-page-header">
                <div>
                    <p class="ifp-kicker">Ice &amp; Field Productions</p>
                    <h1><?php echo esc_html($title); ?></h1>
                    <p class="ifp-lead"><?php echo esc_html($lead); ?></p>
                </div>
                <div class="ifp-header-actions">
                    <a class="button" href="<?php echo esc_url(self::history_url()); ?>">Communication History</a>
                    <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=' . self::TEMPLATE_TYPE)); ?>">Email Templates</a>
                </div>
            </div>
        <?php
    }

    private static function render_notice() {
        $status = sanitize_key((string) wp_unslash($_GET['ifp_comm_notice'] ?? ''));
        $message = sanitize_text_field((string) wp_unslash($_GET['ifp_comm_message'] ?? ''));
        if ($status === '' || $message === '') return;
        $class = $status === 'success' ? 'notice-success' : ($status === 'warning' ? 'notice-warning' : 'notice-error');
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private static function render_compose() {
        $submitted = $_SERVER['REQUEST_METHOD'] === 'POST' ? wp_unslash($_POST) : [];
        $template_id = absint($_GET['template_id'] ?? ($submitted['ifp_template_id'] ?? 0));
        $template = $template_id ? get_post($template_id) : null;
        if (!$template || $template->post_type !== self::TEMPLATE_TYPE) $template = null;

        $subject = sanitize_text_field((string) ($submitted['ifp_communication_subject'] ?? ($template ? $template->post_title : '')));
        $body = wp_kses_post((string) ($submitted['ifp_communication_body'] ?? ($template ? $template->post_content : '')));
        $recipient_type = sanitize_key((string) ($submitted['ifp_recipient_type'] ?? ($_GET['recipient_type'] ?? 'production')));
        if (!in_array($recipient_type, ['production','division','group','people'], true)) $recipient_type = 'production';
        $production_id = absint($submitted['ifp_production_id'] ?? ($_GET['production_id'] ?? 0));
        $division_id = absint($submitted['ifp_division_id'] ?? ($_GET['division_id'] ?? 0));
        $group_id = absint($submitted['ifp_group_id'] ?? ($_GET['group_id'] ?? 0));
        $person_ids = array_values(array_unique(array_filter(array_map(
            'absint',
            (array) ($submitted['ifp_person_ids'] ?? ($_GET['person_ids'] ?? []))
        ))));
        if (!$person_ids && !empty($_GET['all_people'])) {
            $person_ids = get_posts([
                'post_type' => 'ifp_participant',
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
            ]);
            $person_ids = array_values(array_unique(array_filter(array_map('absint', $person_ids))));
        }
        $templates = self::templates();
        ?>
        <div class="ifp-communication-layout">
            <form class="ifp-communication-compose" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="ifp-communications">
                <input type="hidden" name="recipient_type" value="<?php echo esc_attr($recipient_type); ?>">
                <?php if ($recipient_type === 'production' && $production_id): ?><input type="hidden" name="production_id" value="<?php echo esc_attr($production_id); ?>"><?php endif; ?>
                <?php if ($recipient_type === 'division' && $division_id): ?><input type="hidden" name="division_id" value="<?php echo esc_attr($division_id); ?>"><?php endif; ?>
                <?php if ($recipient_type === 'group' && $group_id): ?><input type="hidden" name="group_id" value="<?php echo esc_attr($group_id); ?>"><?php endif; ?>
                <?php if ($recipient_type === 'people'): ?>
                    <?php foreach ($person_ids as $person_id): ?><input type="hidden" name="person_ids[]" value="<?php echo esc_attr($person_id); ?>"><?php endforeach; ?>
                <?php endif; ?>
                <section class="ifp-admin-card ifp-template-loader">
                    <h2>Start with a template <span class="ifp-optional">Optional</span></h2>
                    <div class="ifp-inline-controls">
                        <select name="template_id">
                            <option value="0">Blank email</option>
                            <?php foreach ($templates as $saved_template): ?>
                                <option value="<?php echo esc_attr($saved_template->ID); ?>" <?php selected($template_id, $saved_template->ID); ?>><?php echo esc_html($saved_template->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="button">Load Template</button>
                        <a class="button button-link" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . self::TEMPLATE_TYPE)); ?>">Create Template</a>
                    </div>
                    <p class="description">Loading a template replaces the subject and message currently shown in the composer.</p>
                </section>
            </form>

            <form class="ifp-communication-compose" method="post">
                <?php wp_nonce_field('ifp_prepare_communication', 'ifp_communication_nonce'); ?>
                <input type="hidden" name="ifp_template_id" value="<?php echo esc_attr($template_id); ?>">

                <section class="ifp-admin-card">
                    <h2>1. Choose recipients</h2>
                    <div class="ifp-field-grid">
                        <p><label><strong>Send to</strong><br>
                            <select class="widefat" id="ifp-recipient-type" name="ifp_recipient_type">
                                <option value="production" <?php selected($recipient_type, 'production'); ?>>Entire Production</option>
                                <option value="division" <?php selected($recipient_type, 'division'); ?>>Division</option>
                                <option value="group" <?php selected($recipient_type, 'group'); ?>>Group</option>
                                <option value="people" <?php selected($recipient_type, 'people'); ?>>Selected People</option>
                            </select>
                        </label></p>
                    </div>

                    <div class="ifp-audience-panel" data-audience="production">
                        <label><strong>Production</strong><br>
                            <select class="widefat" name="ifp_production_id">
                                <option value="0">— Select Production —</option>
                                <?php foreach (self::productions() as $production): ?>
                                    <option value="<?php echo esc_attr($production->ID); ?>" <?php selected($production_id, $production->ID); ?>><?php echo esc_html($production->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="ifp-audience-panel" data-audience="division">
                        <label><strong>Division</strong><br>
                            <select class="widefat" name="ifp_division_id">
                                <option value="0">— Select Division —</option>
                                <?php foreach (self::divisions() as $division): ?>
                                    <?php $parent = absint(get_post_meta($division->ID, '_ifp_division_production_id', true)); ?>
                                    <option value="<?php echo esc_attr($division->ID); ?>" <?php selected($division_id, $division->ID); ?>><?php echo esc_html($division->post_title . ($parent ? ' — ' . get_the_title($parent) : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="ifp-audience-panel" data-audience="group">
                        <label><strong>Group</strong><br>
                            <select class="widefat" name="ifp_group_id">
                                <option value="0">— Select Group —</option>
                                <?php foreach (self::groups() as $group): ?>
                                    <?php $parent = absint(get_post_meta($group->ID, '_ifp_group_production_id', true)); ?>
                                    <option value="<?php echo esc_attr($group->ID); ?>" <?php selected($group_id, $group->ID); ?>><?php echo esc_html($group->post_title . ($parent ? ' — ' . get_the_title($parent) : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="ifp-audience-panel" data-audience="people">
                        <label><strong>People</strong><br>
                            <select class="widefat ifp-person-picker" name="ifp_person_ids[]" multiple size="10">
                                <?php foreach (self::people() as $person): ?>
                                    <?php $email = sanitize_email((string) get_post_meta($person->ID, '_ifp_participant_email', true)); ?>
                                    <option value="<?php echo esc_attr($person->ID); ?>" <?php echo in_array($person->ID, $person_ids, true) ? 'selected' : ''; ?> <?php disabled($email === ''); ?>><?php echo esc_html($person->post_title . ($email ? ' — ' . $email : ' — no email')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <p class="description">Hold Command on Mac or Control on Windows to choose more than one Person. People without an email address cannot be selected.</p>
                    </div>
                    <p class="description">Production and Division audiences are built from their Groups. Duplicate email addresses receive only one copy.</p>
                </section>

                <section class="ifp-admin-card">
                    <h2>2. Write the email</h2>
                    <p><label><strong>Subject</strong><br><input class="widefat ifp-email-subject" type="text" name="ifp_communication_subject" value="<?php echo esc_attr($subject); ?>" required></label></p>
                    <p><strong>Message</strong></p>
                    <?php
                    wp_editor($body, 'ifp_communication_body_editor', [
                        'textarea_name' => 'ifp_communication_body',
                        'media_buttons' => false,
                        'teeny' => true,
                        'quicktags' => false,
                        'editor_height' => 320,
                        'tinymce' => [
                            'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo',
                            'toolbar2' => '',
                        ],
                    ]);
                    ?>
                    <p class="description">Messages are sent as HTML by default. The visual editor creates the formatting; no HTML knowledge is required.</p>
                    <label class="ifp-save-template-choice"><input type="checkbox" name="ifp_save_as_template" value="1" <?php checked(!empty($submitted['ifp_save_as_template'])); ?>> Save this subject and message as a reusable template when sent</label>
                </section>

                <section class="ifp-communication-next">
                    <div>
                        <strong>Nothing sends from this screen.</strong>
                        <span>You will review the resolved names and email addresses first.</span>
                    </div>
                    <button class="button button-primary button-hero" type="submit" name="ifp_prepare_communication" value="1">Review Email</button>
                </section>
            </form>
        </div>
        <?php
    }

    private static function prepare_preview() {
        $subject = sanitize_text_field((string) wp_unslash($_POST['ifp_communication_subject'] ?? ''));
        $body = wp_kses_post((string) wp_unslash($_POST['ifp_communication_body'] ?? ''));
        if ($subject === '') return new WP_Error('ifp_email_subject_required', 'Enter an email subject.');
        if (trim(wp_strip_all_tags($body)) === '') return new WP_Error('ifp_email_body_required', 'Write an email message.');

        $target = self::target_from_request($_POST);
        if (is_wp_error($target)) return $target;
        $audience = self::resolve_audience($target['type'], $target['ids']);
        if (is_wp_error($audience)) return $audience;
        if (!$audience['recipients']) return new WP_Error('ifp_email_no_recipients', 'No valid recipient email addresses were found for that audience.');
        if (count($audience['recipients']) > self::MAX_RECIPIENTS) {
            return new WP_Error(
                'ifp_email_too_many_recipients',
                'This audience contains more than ' . self::MAX_RECIPIENTS . ' unique email addresses. Choose a smaller Division, Group, or selection of People.'
            );
        }

        $token = wp_generate_password(24, false, false);
        $prepared = [
            'token' => $token,
            'user_id' => get_current_user_id(),
            'created_at' => current_time('mysql'),
            'subject' => $subject,
            'body' => $body,
            'target' => $target,
            'target_label' => $audience['target_label'],
            'recipients' => $audience['recipients'],
            'missing_email_count' => $audience['missing_email_count'],
            'template_id' => absint($_POST['ifp_template_id'] ?? 0),
            'save_as_template' => !empty($_POST['ifp_save_as_template']) ? 1 : 0,
        ];
        set_transient(self::preview_key($token), $prepared, 30 * MINUTE_IN_SECONDS);
        return $prepared;
    }

    private static function render_review($prepared) {
        $recipients = array_values((array) ($prepared['recipients'] ?? []));
        ?>
        <section class="ifp-admin-card ifp-email-review">
            <div class="ifp-preview-heading">
                <div>
                    <p class="ifp-kicker">Final Review</p>
                    <h2><?php echo esc_html($prepared['subject']); ?></h2>
                    <p><strong>Audience:</strong> <?php echo esc_html($prepared['target_label']); ?></p>
                </div>
                <div class="ifp-email-count">
                    <strong><?php echo esc_html(count($recipients)); ?></strong>
                    <span>unique email<?php echo count($recipients) === 1 ? '' : 's'; ?></span>
                </div>
            </div>

            <?php if (!empty($prepared['missing_email_count'])): ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html($prepared['missing_email_count']); ?> <?php echo absint($prepared['missing_email_count']) === 1 ? 'recipient record was' : 'recipient records were'; ?> skipped because no email address is saved.</p></div>
            <?php endif; ?>

            <div class="ifp-email-preview-body">
                <?php echo wp_kses_post(wpautop($prepared['body'])); ?>
            </div>

            <h3>Recipients</h3>
            <div class="ifp-table-scroll">
                <table class="widefat striped ifp-recipient-review">
                    <thead><tr><th>Name</th><th>Email</th><th>Person record</th></tr></thead>
                    <tbody>
                    <?php foreach ($recipients as $recipient): ?>
                        <tr>
                            <td><?php echo esc_html($recipient['name'] ?: '—'); ?></td>
                            <td><?php echo esc_html($recipient['email']); ?></td>
                            <td>
                                <?php if (!empty($recipient['person_ids'])): ?>
                                    <?php foreach ((array) $recipient['person_ids'] as $person_id): ?>
                                        <a href="<?php echo esc_url(get_edit_post_link($person_id)); ?>"><?php echo esc_html(get_the_title($person_id) ?: 'Person #' . absint($person_id)); ?></a><br>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="description">Manual Group roster entry</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="ifp-email-final-actions">
                <form method="post">
                    <?php wp_nonce_field('ifp_return_to_compose', 'ifp_return_nonce'); ?>
                    <input type="hidden" name="ifp_return_to_compose" value="1">
                    <input type="hidden" name="ifp_preview_token" value="<?php echo esc_attr($prepared['token']); ?>">
                    <input type="hidden" name="ifp_recipient_type" value="<?php echo esc_attr($prepared['target']['type']); ?>">
                    <?php if ($prepared['target']['type'] === 'production'): ?><input type="hidden" name="ifp_production_id" value="<?php echo esc_attr($prepared['target']['ids'][0] ?? 0); ?>"><?php endif; ?>
                    <?php if ($prepared['target']['type'] === 'division'): ?><input type="hidden" name="ifp_division_id" value="<?php echo esc_attr($prepared['target']['ids'][0] ?? 0); ?>"><?php endif; ?>
                    <?php if ($prepared['target']['type'] === 'group'): ?><input type="hidden" name="ifp_group_id" value="<?php echo esc_attr($prepared['target']['ids'][0] ?? 0); ?>"><?php endif; ?>
                    <?php if ($prepared['target']['type'] === 'people'): ?>
                        <?php foreach ((array) $prepared['target']['ids'] as $person_id): ?><input type="hidden" name="ifp_person_ids[]" value="<?php echo esc_attr($person_id); ?>"><?php endforeach; ?>
                    <?php endif; ?>
                    <input type="hidden" name="ifp_communication_subject" value="<?php echo esc_attr($prepared['subject']); ?>">
                    <textarea name="ifp_communication_body" hidden><?php echo esc_textarea($prepared['body']); ?></textarea>
                    <input type="hidden" name="ifp_template_id" value="<?php echo esc_attr($prepared['template_id']); ?>">
                    <?php if (!empty($prepared['save_as_template'])): ?><input type="hidden" name="ifp_save_as_template" value="1"><?php endif; ?>
                    <button type="submit" class="button">Back to Edit</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Send this HTML email to <?php echo esc_js(count($recipients)); ?> unique address<?php echo count($recipients) === 1 ? '' : 'es'; ?>?');">
                    <input type="hidden" name="action" value="ifp_send_communication">
                    <input type="hidden" name="ifp_preview_token" value="<?php echo esc_attr($prepared['token']); ?>">
                    <?php wp_nonce_field('ifp_send_communication_' . $prepared['token']); ?>
                    <button class="button button-primary button-hero" type="submit">Send <?php echo esc_html(count($recipients)); ?> Email<?php echo count($recipients) === 1 ? '' : 's'; ?></button>
                </form>
            </div>
            <p class="description">Each unique address receives a private copy. “Sent” in the history means WordPress handed the message to the website’s mail service; it does not yet confirm delivery or opens.</p>
        </section>
        <?php
    }

    public static function send_action() {
        if (!current_user_can('edit_ifp_people')) {
            wp_die('You do not have permission to send production email.');
        }

        $token = sanitize_key((string) wp_unslash($_POST['ifp_preview_token'] ?? ''));
        if ($token === '') wp_die('The email review has expired or is invalid.');
        check_admin_referer('ifp_send_communication_' . $token);

        $prepared = get_transient(self::preview_key($token));
        if (!is_array($prepared) || absint($prepared['user_id'] ?? 0) !== get_current_user_id()) {
            self::redirect_with_notice('error', 'That email review expired. Please review the message again before sending.');
        }
        delete_transient(self::preview_key($token));

        $recipients = array_values((array) ($prepared['recipients'] ?? []));
        if (!$recipients || count($recipients) > self::MAX_RECIPIENTS) {
            self::redirect_with_notice('error', 'The reviewed recipient list was empty or too large to send safely.');
        }

        $communication_id = wp_insert_post(wp_slash([
            'post_type' => self::COMMUNICATION_TYPE,
            'post_status' => 'publish',
            'post_title' => sanitize_text_field((string) ($prepared['subject'] ?? 'Production email')),
            'post_content' => wp_kses_post((string) ($prepared['body'] ?? '')),
            'post_author' => get_current_user_id(),
        ]), true);
        if (is_wp_error($communication_id)) {
            self::redirect_with_notice('error', 'The communication history record could not be created, so no email was sent.');
        }
        $communication_id = absint($communication_id);

        $subject = sanitize_text_field((string) $prepared['subject']);
        $html = self::html_email((string) $prepared['body']);
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $results = [];
        $success_count = 0;
        $failure_count = 0;
        $person_ids = [];

        foreach ($recipients as $recipient) {
            $email = sanitize_email((string) ($recipient['email'] ?? ''));
            if ($email === '') continue;
            $sent = wp_mail($email, $subject, $html, $headers);
            if ($sent) $success_count++;
            else $failure_count++;

            $recipient_person_ids = array_values(array_unique(array_filter(array_map(
                'absint',
                (array) ($recipient['person_ids'] ?? [])
            ))));
            $person_ids = array_merge($person_ids, $recipient_person_ids);
            $results[] = [
                'email' => $email,
                'name' => sanitize_text_field((string) ($recipient['name'] ?? '')),
                'person_ids' => $recipient_person_ids,
                'status' => $sent ? 'sent' : 'failed',
            ];
        }

        $person_ids = array_values(array_unique(array_filter(array_map('absint', $person_ids))));
        update_post_meta($communication_id, '_ifp_comm_sent_at', current_time('mysql'));
        update_post_meta($communication_id, '_ifp_comm_sent_by', get_current_user_id());
        update_post_meta($communication_id, '_ifp_comm_target_type', sanitize_key((string) ($prepared['target']['type'] ?? '')));
        update_post_meta($communication_id, '_ifp_comm_target_ids', array_values(array_map('absint', (array) ($prepared['target']['ids'] ?? []))));
        update_post_meta($communication_id, '_ifp_comm_target_label', sanitize_text_field((string) ($prepared['target_label'] ?? '')));
        update_post_meta($communication_id, '_ifp_comm_recipient_results', $results);
        update_post_meta($communication_id, '_ifp_comm_person_ids', $person_ids);
        update_post_meta($communication_id, '_ifp_comm_success_count', $success_count);
        update_post_meta($communication_id, '_ifp_comm_failure_count', $failure_count);
        update_post_meta($communication_id, '_ifp_comm_missing_email_count', absint($prepared['missing_email_count'] ?? 0));
        update_post_meta($communication_id, '_ifp_comm_template_id', absint($prepared['template_id'] ?? 0));

        foreach ($person_ids as $person_id) self::connect_person_history($person_id, $communication_id);

        if (!empty($prepared['save_as_template'])) {
            $template_id = wp_insert_post(wp_slash([
                'post_type' => self::TEMPLATE_TYPE,
                'post_status' => 'publish',
                'post_title' => $subject,
                'post_content' => wp_kses_post((string) $prepared['body']),
                'post_author' => get_current_user_id(),
            ]), true);
            if (!is_wp_error($template_id)) update_post_meta($communication_id, '_ifp_comm_saved_template_id', absint($template_id));
        }

        $message = $success_count . ' ' . ($success_count === 1 ? 'email was' : 'emails were') . ' handed to the website mail service.';
        if ($failure_count) $message .= ' ' . $failure_count . ' failed and can be reviewed in the history.';
        $status = $failure_count ? ($success_count ? 'warning' : 'error') : 'success';
        self::redirect_with_notice($status, $message, $communication_id);
    }

    private static function connect_person_history($person_id, $communication_id) {
        if (get_post_type($person_id) !== 'ifp_participant') return;
        $history = array_values(array_unique(array_filter(array_map(
            'absint',
            (array) get_post_meta($person_id, self::PERSON_HISTORY_KEY, true)
        ))));
        $history[] = absint($communication_id);
        $history = array_slice(array_values(array_unique($history)), -200);
        update_post_meta($person_id, self::PERSON_HISTORY_KEY, $history);
        update_post_meta($person_id, '_ifp_last_communication_at', current_time('mysql'));
    }

    private static function html_email($body) {
        $body = wp_kses_post(wpautop((string) $body));
        return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>' .
            '<body style="margin:0;padding:0;background:#f3f5f7;color:#233746;font-family:Arial,Helvetica,sans-serif;line-height:1.6">' .
            '<div style="max-width:680px;margin:0 auto;padding:28px 16px"><div style="background:#ffffff;border:1px solid #d8e0e6;border-radius:12px;padding:28px">' .
            $body .
            '</div></div></body></html>';
    }

    private static function target_from_request($request) {
        $type = sanitize_key((string) wp_unslash($request['ifp_recipient_type'] ?? ''));
        if (!in_array($type, ['production','division','group','people'], true)) {
            return new WP_Error('ifp_email_audience_required', 'Choose who should receive the email.');
        }

        if ($type === 'production') {
            $id = absint($request['ifp_production_id'] ?? 0);
            if (!$id || get_post_type($id) !== 'ifp_production') return new WP_Error('ifp_email_production_required', 'Choose a Production.');
            return ['type' => $type, 'ids' => [$id]];
        }
        if ($type === 'division') {
            $id = absint($request['ifp_division_id'] ?? 0);
            if (!$id || get_post_type($id) !== 'ifp_division') return new WP_Error('ifp_email_division_required', 'Choose a Division.');
            return ['type' => $type, 'ids' => [$id]];
        }
        if ($type === 'group') {
            $id = absint($request['ifp_group_id'] ?? 0);
            if (!$id || get_post_type($id) !== 'ifp_group') return new WP_Error('ifp_email_group_required', 'Choose a Group.');
            return ['type' => $type, 'ids' => [$id]];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            'absint',
            (array) wp_unslash($request['ifp_person_ids'] ?? [])
        ))));
        $ids = array_values(array_filter($ids, function($id) {
            return get_post_type($id) === 'ifp_participant';
        }));
        if (!$ids) return new WP_Error('ifp_email_people_required', 'Choose at least one Person.');
        return ['type' => $type, 'ids' => $ids];
    }

    public static function resolve_audience($type, $ids) {
        $type = sanitize_key((string) $type);
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
        $group_ids = [];
        $person_ids = [];
        $target_label = '';

        if ($type === 'production') {
            $production_id = absint($ids[0] ?? 0);
            if (!$production_id || get_post_type($production_id) !== 'ifp_production') return new WP_Error('ifp_email_invalid_production', 'The selected Production no longer exists.');
            $group_ids = get_posts([
                'post_type' => 'ifp_group', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
                'meta_key' => '_ifp_group_production_id', 'meta_value' => $production_id,
            ]);
            $target_label = 'Production: ' . get_the_title($production_id);
        } elseif ($type === 'division') {
            $division_id = absint($ids[0] ?? 0);
            if (!$division_id || get_post_type($division_id) !== 'ifp_division') return new WP_Error('ifp_email_invalid_division', 'The selected Division no longer exists.');
            $group_ids = get_posts([
                'post_type' => 'ifp_group', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
                'meta_key' => '_ifp_group_division_id', 'meta_value' => $division_id,
            ]);
            $target_label = 'Division: ' . get_the_title($division_id);
        } elseif ($type === 'group') {
            $group_id = absint($ids[0] ?? 0);
            if (!$group_id || get_post_type($group_id) !== 'ifp_group') return new WP_Error('ifp_email_invalid_group', 'The selected Group no longer exists.');
            $group_ids = [$group_id];
            $target_label = 'Group: ' . get_the_title($group_id);
        } elseif ($type === 'people') {
            $person_ids = array_values(array_filter($ids, function($id) {
                return get_post_type($id) === 'ifp_participant';
            }));
            $target_label = count($person_ids) . ' selected ' . (count($person_ids) === 1 ? 'Person' : 'People');
        } else {
            return new WP_Error('ifp_email_invalid_audience', 'The selected audience is invalid.');
        }

        $recipients = [];
        $considered_people = [];
        $roster_email_fallbacks = [];
        $missing_manual_count = 0;
        foreach ($group_ids as $group_id) {
            $roster = get_post_meta($group_id, '_ifp_group_participants', true);
            foreach ((array) $roster as $row) {
                if (!is_array($row)) continue;
                $person_id = absint($row['participant_id'] ?? 0);
                if ($person_id) {
                    $person_ids[] = $person_id;
                    $person_email = sanitize_email((string) get_post_meta($person_id, '_ifp_participant_email', true));
                    $roster_email = sanitize_email((string) ($row['email'] ?? ''));
                    if ($person_email === '' && $roster_email !== '') {
                        self::add_recipient(
                            $recipients,
                            $roster_email,
                            trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
                            [$person_id]
                        );
                        $roster_email_fallbacks[$person_id] = true;
                    }
                    continue;
                }
                $email = sanitize_email((string) ($row['email'] ?? ''));
                if ($email === '') {
                    if (trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')) !== '') $missing_manual_count++;
                    continue;
                }
                self::add_recipient($recipients, $email, trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')), []);
            }
            $person_ids = array_merge($person_ids, (array) get_post_meta($group_id, '_ifp_group_participant_ids', true));
        }

        $person_ids = array_values(array_unique(array_filter(array_map('absint', $person_ids))));
        foreach ($person_ids as $person_id) {
            if (get_post_type($person_id) !== 'ifp_participant') continue;
            $considered_people[$person_id] = true;
            $email = sanitize_email((string) get_post_meta($person_id, '_ifp_participant_email', true));
            if ($email === '') continue;
            self::add_recipient($recipients, $email, get_the_title($person_id), [$person_id]);
        }

        $missing_email_count = $missing_manual_count;
        foreach (array_keys($considered_people) as $person_id) {
            if (
                sanitize_email((string) get_post_meta($person_id, '_ifp_participant_email', true)) === '' &&
                empty($roster_email_fallbacks[$person_id])
            ) $missing_email_count++;
        }

        ksort($recipients, SORT_NATURAL | SORT_FLAG_CASE);
        return [
            'recipients' => array_values($recipients),
            'target_label' => sanitize_text_field($target_label),
            'missing_email_count' => $missing_email_count,
        ];
    }

    private static function add_recipient(&$recipients, $email, $name, $person_ids) {
        $email = sanitize_email((string) $email);
        if ($email === '') return;
        $key = strtolower($email);
        $person_ids = array_values(array_unique(array_filter(array_map('absint', (array) $person_ids))));
        if (!isset($recipients[$key])) {
            $recipients[$key] = [
                'email' => $email,
                'name' => sanitize_text_field((string) $name),
                'person_ids' => $person_ids,
            ];
            return;
        }
        $recipients[$key]['person_ids'] = array_values(array_unique(array_merge(
            (array) $recipients[$key]['person_ids'],
            $person_ids
        )));
        if ($recipients[$key]['name'] === '' && $name !== '') $recipients[$key]['name'] = sanitize_text_field((string) $name);
    }

    private static function render_history_page() {
        self::render_header('Communication History', 'Review every recorded HTML email, its audience, and whether WordPress handed each copy to the mail service.');
        self::render_notice();

        $communication_id = absint($_GET['communication_id'] ?? 0);
        if ($communication_id) self::render_history_detail($communication_id);

        $page = max(1, absint($_GET['paged'] ?? 1));
        $query = new WP_Query([
            'post_type' => self::COMMUNICATION_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 25,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        ?>
        <section class="ifp-admin-card ifp-communication-history">
            <div class="ifp-preview-heading">
                <div><h2>Sent Messages</h2><p>History is local to WordPress and is never synchronized back to Dash.</p></div>
                <a class="button button-primary" href="<?php echo esc_url(self::compose_url()); ?>">Compose Email</a>
            </div>
            <?php if (!$query->have_posts()): ?>
                <div class="ifp-communication-empty"><span class="dashicons dashicons-email-alt"></span><h3>No communication history yet</h3><p>Your first sent message will appear here.</p></div>
            <?php else: ?>
                <div class="ifp-table-scroll">
                    <table class="widefat striped ifp-history-table">
                        <thead><tr><th>Sent</th><th>Subject</th><th>Audience</th><th>Result</th><th>Sent by</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($query->posts as $communication): ?>
                            <?php
                            $success = absint(get_post_meta($communication->ID, '_ifp_comm_success_count', true));
                            $failed = absint(get_post_meta($communication->ID, '_ifp_comm_failure_count', true));
                            $sent_at = (string) get_post_meta($communication->ID, '_ifp_comm_sent_at', true);
                            $sender = get_userdata(absint(get_post_meta($communication->ID, '_ifp_comm_sent_by', true)));
                            ?>
                            <tr>
                                <td><?php echo esc_html(self::admin_datetime($sent_at)); ?></td>
                                <td><strong><?php echo esc_html($communication->post_title); ?></strong></td>
                                <td><?php echo esc_html((string) get_post_meta($communication->ID, '_ifp_comm_target_label', true)); ?></td>
                                <td><span class="ifp-email-result <?php echo $failed ? 'ifp-email-result--failed' : 'ifp-email-result--sent'; ?>"><?php echo esc_html($success . ' handed off' . ($failed ? ', ' . $failed . ' failed' : '')); ?></span></td>
                                <td><?php echo esc_html($sender ? $sender->display_name : 'Unknown'); ?></td>
                                <td><a class="button" href="<?php echo esc_url(self::history_url($communication->ID)); ?>">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($query->max_num_pages > 1): ?>
                    <div class="tablenav"><div class="tablenav-pages">
                        <?php echo wp_kses_post(paginate_links([
                            'base' => add_query_arg('paged', '%#%', self::history_url()),
                            'format' => '',
                            'current' => $page,
                            'total' => $query->max_num_pages,
                        ])); ?>
                    </div></div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        </div>
        <?php
        wp_reset_postdata();
    }

    private static function render_history_detail($communication_id) {
        $communication = get_post($communication_id);
        if (!$communication || $communication->post_type !== self::COMMUNICATION_TYPE) {
            echo '<div class="notice notice-error"><p>That communication record could not be found.</p></div>';
            return;
        }
        $results = (array) get_post_meta($communication_id, '_ifp_comm_recipient_results', true);
        ?>
        <section class="ifp-admin-card ifp-history-detail">
            <div class="ifp-preview-heading">
                <div><p class="ifp-kicker">Message Detail</p><h2><?php echo esc_html($communication->post_title); ?></h2><p><?php echo esc_html((string) get_post_meta($communication_id, '_ifp_comm_target_label', true)); ?> · <?php echo esc_html(self::admin_datetime((string) get_post_meta($communication_id, '_ifp_comm_sent_at', true))); ?></p></div>
                <a class="button" href="<?php echo esc_url(self::history_url()); ?>">Close Detail</a>
            </div>
            <div class="ifp-email-preview-body"><?php echo wp_kses_post(wpautop($communication->post_content)); ?></div>
            <details class="ifp-recipient-results">
                <summary><?php echo esc_html(count($results)); ?> recipient result<?php echo count($results) === 1 ? '' : 's'; ?></summary>
                <div class="ifp-table-scroll"><table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Result</th></tr></thead><tbody>
                    <?php foreach ($results as $result): ?>
                        <tr><td><?php echo esc_html($result['name'] ?? '—'); ?></td><td><?php echo esc_html($result['email'] ?? ''); ?></td><td><span class="ifp-email-result ifp-email-result--<?php echo esc_attr($result['status'] ?? 'failed'); ?>"><?php echo ($result['status'] ?? '') === 'sent' ? 'Handed to mail service' : 'Failed'; ?></span></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            </details>
        </section>
        <?php
    }

    private static function person_result($communication_id, $person_id) {
        foreach ((array) get_post_meta($communication_id, '_ifp_comm_recipient_results', true) as $result) {
            if (!is_array($result)) continue;
            if (in_array(absint($person_id), array_map('absint', (array) ($result['person_ids'] ?? [])), true)) {
                return ($result['status'] ?? '') === 'sent' ? 'sent' : 'failed';
            }
        }
        return 'failed';
    }

    private static function preview_key($token) {
        return 'ifp_comm_preview_' . get_current_user_id() . '_' . sanitize_key((string) $token);
    }

    private static function redirect_with_notice($status, $message, $communication_id = 0) {
        $args = [
            'page' => 'ifp-communications',
            'view' => $communication_id ? 'history' : 'compose',
            'ifp_comm_notice' => sanitize_key((string) $status),
            'ifp_comm_message' => sanitize_text_field((string) $message),
        ];
        if ($communication_id) $args['communication_id'] = absint($communication_id);
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private static function admin_datetime($value) {
        $value = sanitize_text_field((string) $value);
        if ($value === '') return 'Unknown';
        $formatted = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $value);
        return $formatted ?: $value;
    }

    private static function templates() {
        return get_posts([
            'post_type' => self::TEMPLATE_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
    }

    private static function productions() {
        return get_posts([
            'post_type' => 'ifp_production',
            'post_status' => ['publish','draft','future','private'],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
    }

    private static function divisions() {
        return get_posts([
            'post_type' => 'ifp_division',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
    }

    private static function groups() {
        return get_posts([
            'post_type' => 'ifp_group',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
    }

    private static function people() {
        return get_posts([
            'post_type' => 'ifp_participant',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
    }
}
