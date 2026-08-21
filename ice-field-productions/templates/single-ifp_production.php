<?php
if (!defined('ABSPATH')) exit;

get_header();

while (have_posts()) :
    the_post();
    $production_id = get_the_ID();

    if (IFP_Production_Status::get($production_id) !== 'current') {
        include IFP_DIR . 'templates/production-showcase.php';
        continue;
    }
    ?>
    <main class="ifp-production-participant-page">
        <?php
        echo do_shortcode(
            '[ifp_participant_hub production_id="' . absint($production_id) . '"]'
        );
        ?>
    </main>
    <?php
endwhile;

get_footer();
