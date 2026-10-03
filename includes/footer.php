  </main>

  <footer class="app-footer">
    <div class="container-fluid d-flex flex-wrap justify-content-between gap-2 no-print">
      <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> — Multi-company inventory &amp; stock movement system</span>
      <span class="text-muted">Signed in as <?= e($USER['username']) ?> &middot; <?= e($scope_label) ?> &middot; <?= e(date('d-M-Y H:i')) ?></span>
    </div>
    <div class="container-fluid rt-attribution-bar">
      <?= rt_attribution_html('print') ?>
    </div>
  </footer>

  <?= rt_attribution_html('fixed') ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="<?= e(url('assets/js/app.js')) ?>"></script>
  </body>
  </html>
