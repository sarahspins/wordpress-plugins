<?php get_header(); ?>
<main class="content-area">
  <?php while (have_posts()) : the_post(); ?>
    <article <?php post_class(); ?>>
      <div class="entry-content">
        <?php
        if (trim(get_the_content())) {
          the_content();
        } else {
          ?>
          <section class="section">
            <div class="container">
              <div class="entry-card">
                <span class="eyebrow">Homepage setup</span>
                <h1 class="entry-title" style="display:block">Build your homepage with blocks</h1>
                <p>This homepage is now fully editable. Open this page in the WordPress Block Editor, click the blue <strong>+</strong> button, select <strong>Patterns</strong>, and choose the <strong>Ice & Field Productions</strong> category.</p>
                <p>Start with the Show Hero pattern, then add Join / Watch / Support, Production Story, Participant Hub, images, galleries, video, sponsor logos, or any standard WordPress block.</p>
              </div>
            </div>
          </section>
          <?php
        }
        ?>
      </div>
    </article>
  <?php endwhile; ?>
</main>
<?php get_footer(); ?>
