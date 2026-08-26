<?php
if (!defined('ABSPATH')) exit;

/** Shared, observable delivery wrapper for Connector-generated email. */
class IFDC_Mailer {
    const STATUS_OPTION = 'ifdc_mail_delivery_status';
    const HISTORY_OPTION = 'ifdc_mail_delivery_history';
    const MAX_HISTORY = 25;

    public static function menu() {
        add_submenu_page(
            'ifdc-dashboard',
            'Email History',
            'Email History',
            'manage_options',
            'ifdc-email-history',
            [__CLASS__, 'history_page']
        );
    }

    public static function send($context, $recipients, $subject, $message, $headers = []) {
        $recipients = array_values(array_unique(array_filter(array_map('sanitize_email', (array) $recipients), 'is_email')));
        $status = [
            'attempted_at' => time(),
            'subject' => sanitize_text_field($subject),
            'recipients' => $recipients,
            'accepted' => [],
            'failed' => [],
        ];
        foreach ($recipients as $recipient) {
            $failure = null;
            $capture = function($error) use (&$failure) {
                if (is_wp_error($error)) $failure = $error->get_error_message();
            };
            add_action('wp_mail_failed', $capture);
            $accepted = wp_mail($recipient, $subject, $message, $headers);
            remove_action('wp_mail_failed', $capture);
            if ($accepted) $status['accepted'][] = $recipient;
            else $status['failed'][$recipient] = $failure ?: 'WordPress mail transport rejected the message without an error description.';
        }
        if (!$recipients) $status['failed']['recipients'] = 'No valid recipients were saved.';
        $all = (array) get_option(self::STATUS_OPTION, []);
        $all[sanitize_key($context)] = $status;
        update_option(self::STATUS_OPTION, $all, false);
        $history = (array) get_option(self::HISTORY_OPTION, []);
        $entry = $status;
        $entry['id'] = wp_generate_uuid4();
        $entry['context'] = sanitize_key($context);
        $entry['message'] = function_exists('mb_substr') ? mb_substr((string) $message, 0, 100000) : substr((string) $message, 0, 100000);
        $entry['headers'] = array_map('sanitize_text_field', (array) $headers);
        $history = array_slice(array_merge([$entry], $history), 0, self::MAX_HISTORY);
        if (get_option(self::HISTORY_OPTION, null) === null) add_option(self::HISTORY_OPTION, $history, '', false);
        else update_option(self::HISTORY_OPTION, $history, false);
        return !$status['failed'] && count($status['accepted']) === count($recipients) && (bool) $recipients;
    }

    public static function status($context) {
        $all = (array) get_option(self::STATUS_OPTION, []);
        return is_array($all[sanitize_key($context)] ?? null) ? $all[sanitize_key($context)] : [];
    }

    public static function history_page() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        $history = (array) get_option(self::HISTORY_OPTION, []);
        ?>
        <div class="wrap ifdc-wrap">
            <h1>Email History</h1>
            <p class="ifdc-lead">The newest <?php echo esc_html(self::MAX_HISTORY); ?> Connector email attempts are retained. “Accepted” means WordPress handed the message to its configured mail transport; it does not prove inbox delivery.</p>
            <section class="ifdc-card">
                <?php if (!$history): ?>
                    <div class="ifdc-empty-state"><span class="dashicons dashicons-email-alt"></span><h3>No email attempts recorded</h3><p>Automatic event-update and weekly ice-schedule messages will appear here.</p></div>
                <?php else: ?>
                    <div class="ifdc-table-wrap"><table class="widefat striped"><thead><tr><th>Attempted</th><th>Message</th><th>Recipients</th><th>Result</th><th>Content</th></tr></thead><tbody>
                    <?php foreach ($history as $item):
                        $failed = (array) ($item['failed'] ?? []);
                        $labels = [
                            'automatic_event_updates' => 'Automatic event updates',
                            'weekly_ice_cuts' => 'Weekly ice cuts',
                            'weekly_schedule_gaps' => 'Weekly schedule gaps',
                            'weekly_ice_schedule' => 'Legacy combined ice schedule',
                        ];
                        $context = $labels[$item['context'] ?? ''] ?? 'Connector email';
                    ?>
                        <tr>
                            <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), absint($item['attempted_at'] ?? 0))); ?></td>
                            <td><strong><?php echo esc_html($context); ?></strong><br><?php echo esc_html($item['subject'] ?? ''); ?></td>
                            <td><?php echo esc_html(implode(', ', (array) ($item['recipients'] ?? []))); ?></td>
                            <td><span class="ifdc-status <?php echo $failed ? 'is-warning' : 'is-ready'; ?>"><?php echo $failed ? 'Failed' : 'Accepted'; ?></span><?php if ($failed): ?><br><small><?php echo esc_html(implode(' | ', $failed)); ?></small><?php endif; ?></td>
                            <td><details><summary>View message</summary><pre class="ifdc-mail-history-message"><?php echo esc_html(wp_strip_all_tags((string) ($item['message'] ?? ''), true)); ?></pre></details></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }
}
