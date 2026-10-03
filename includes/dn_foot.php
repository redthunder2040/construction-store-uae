</div><!-- /report-wrap -->
<div class="report-footer">
  <div class="no-print">
    <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> &middot; report generated <?= e(date('d-M-Y H:i')) ?>
    &middot; user <?= e(current_user()['username']) ?>
  </div>
  <div class="rt-attribution-bar">
    <?= rt_attribution_html('print') ?>
  </div>
</div>
<?= rt_attribution_html('fixed') ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
