<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div>
        <?php if (is_active_sidebar('footer-intro')) : ?>
          <?php dynamic_sidebar('footer-intro'); ?>
        <?php else : ?>
          <h3>
            <?php
            $footer_heading = get_theme_mod('ifp_footer_heading', '');
            echo esc_html($footer_heading ? $footer_heading : get_bloginfo('name'));
            ?>
          </h3>
          <p><?php echo esc_html(get_theme_mod('ifp_footer_intro', 'Celebrating skating, storytelling, and unforgettable moments on ice.')); ?></p>
        <?php endif; ?>
      </div>

      <div>
        <?php if (is_active_sidebar('footer-1')) : ?>
          <?php dynamic_sidebar('footer-1'); ?>
        <?php else : ?>
          <h4>The Show</h4>
          <?php
          wp_nav_menu(array(
            'theme_location' => 'footer_show',
            'container'      => false,
            'fallback_cb'    => 'ifp_footer_show_fallback',
          ));
          ?>
        <?php endif; ?>
      </div>

      <div>
        <?php if (is_active_sidebar('footer-2')) : ?>
          <?php dynamic_sidebar('footer-2'); ?>
        <?php else : ?>
          <h4>Participants</h4>
          <?php
          wp_nav_menu(array(
            'theme_location' => 'footer_participants',
            'container'      => false,
            'fallback_cb'    => 'ifp_footer_participants_fallback',
          ));
          ?>
        <?php endif; ?>
      </div>

      <div>
        <?php if (is_active_sidebar('footer-3')) : ?>
          <?php dynamic_sidebar('footer-3'); ?>
        <?php else : ?>
          <h4>Audience</h4>
          <?php
          wp_nav_menu(array(
            'theme_location' => 'footer_audience',
            'container'      => false,
            'fallback_cb'    => 'ifp_footer_audience_fallback',
          ));
          ?>
        <?php endif; ?>
      </div>

      <div>
        <?php if (is_active_sidebar('footer-4')) : ?>
          <?php dynamic_sidebar('footer-4'); ?>
        <?php else : ?>
          <h4>Support</h4>
          <?php
          wp_nav_menu(array(
            'theme_location' => 'footer_support',
            'container'      => false,
            'fallback_cb'    => 'ifp_footer_support_fallback',
          ));
          ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="footer-bottom">
      <?php if (is_active_sidebar('footer-bottom')) : ?>
        <?php dynamic_sidebar('footer-bottom'); ?>
      <?php else : ?>
        <div>
          © <?php echo esc_html(date('Y')); ?>
          <?php echo esc_html(get_theme_mod('ifp_footer_copyright', 'Ice & Field at The Crossover')); ?>
        </div>
        <?php
        wp_nav_menu(array(
          'theme_location' => 'footer_legal',
          'container'      => false,
          'menu_class'     => 'footer-legal-menu',
          'fallback_cb'    => false,
        ));
        ?>
      <?php endif; ?>
    </div>
  </div>
</footer>
</div>
<?php wp_footer(); ?>
</body>
</html>

<?php
function ifp_footer_show_fallback() {
  echo '<ul>
    <li><a href="' . esc_url(home_url('/productions/')) . '">Current Production</a></li>
    <li><a href="' . esc_url(home_url('/productions/')) . '">Past Productions</a></li>
    <li><a href="' . esc_url(home_url('/gallery/')) . '">Gallery</a></li>
  </ul>';
}

function ifp_footer_participants_fallback() {
  echo '<ul>
    <li><a href="' . esc_url(home_url('/participant-hub/')) . '">Participant Hub</a></li>
    <li><a href="' . esc_url(home_url('/rehearsals/')) . '">Rehearsals</a></li>
    <li><a href="' . esc_url(home_url('/costumes/')) . '">Costumes</a></li>
  </ul>';
}

function ifp_footer_audience_fallback() {
  echo '<ul>
    <li><a href="' . esc_url(get_theme_mod('ifp_ticket_url', '#')) . '">Tickets</a></li>
    <li><a href="' . esc_url(home_url('/venue/')) . '">Venue & Parking</a></li>
    <li><a href="' . esc_url(home_url('/accessibility/')) . '">Accessibility</a></li>
  </ul>';
}

function ifp_footer_support_fallback() {
  echo '<ul>
    <li><a href="' . esc_url(home_url('/sponsors/')) . '">Sponsors</a></li>
    <li><a href="' . esc_url(home_url('/program-ads/')) . '">Program Advertising</a></li>
    <li><a href="' . esc_url(home_url('/volunteer/')) . '">Volunteer</a></li>
  </ul>';
}
?>
