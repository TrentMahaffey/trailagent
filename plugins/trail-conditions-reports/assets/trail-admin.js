/**
 * Trail Map Admin - GPX Import and Status Management
 */
(function() {
  const { apiUrl, nonce } = TCR_TRAIL_ADMIN;

  /**
   * Import GPX files
   */
  document.getElementById('import-gpx-btn')?.addEventListener('click', function() {
    const btn = this;
    const status = document.getElementById('import-status');

    btn.disabled = true;
    btn.textContent = 'Importing...';
    status.innerHTML = '<div class="notice notice-info"><p>⏳ Importing GPX files, please wait...</p></div>';

    fetch(`${apiUrl}trails/import-gpx`, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': nonce,
        'Content-Type': 'application/json'
      }
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        status.innerHTML = `
          <div class="notice notice-success">
            <p><strong>✅ Import Complete!</strong></p>
            <p>Imported or updated ${data.imported} trails.</p>
            ${data.errors.length > 0 ? `<p class="errors"><strong>Errors:</strong><br>${data.errors.join('<br>')}</p>` : ''}
          </div>
        `;

        // Reload the page after 2 seconds to show updated trails
        setTimeout(() => {
          window.location.reload();
        }, 2000);
      } else {
        status.innerHTML = `
          <div class="notice notice-error">
            <p><strong>❌ Import Failed</strong></p>
            <p>${data.message || 'Unknown error occurred'}</p>
          </div>
        `;
        btn.disabled = false;
        btn.textContent = 'Import GPX Files';
      }
    })
    .catch(err => {
      console.error('Import error:', err);
      status.innerHTML = `
        <div class="notice notice-error">
          <p><strong>❌ Import Failed</strong></p>
          <p>Network or server error. Check console for details.</p>
        </div>
      `;
      btn.disabled = false;
      btn.textContent = 'Import GPX Files';
    });
  });

  /**
   * Update trail status
   */
  document.querySelectorAll('.update-status-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      const trailId = this.dataset.trailId;
      const select = document.querySelector(`.status-select[data-trail-id="${trailId}"]`);
      const closeInput = document.querySelector(`.seasonal-close-date[data-trail-id="${trailId}"]`);
      const openInput = document.querySelector(`.seasonal-open-date[data-trail-id="${trailId}"]`);

      const newStatus = select.value;
      const closeDate = closeInput.value;
      const openDate = openInput.value;
      const originalText = this.textContent;

      this.disabled = true;
      this.textContent = 'Updating...';

      fetch(`${apiUrl}trails/${trailId}/status`, {
        method: 'POST',
        headers: {
          'X-WP-Nonce': nonce,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          status: newStatus,
          close_date: closeDate,
          open_date: openDate
        })
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          // Update the status badge
          const row = this.closest('tr');
          const badge = row.querySelector('.status-badge');

          const statusLabels = {
            'open': '✅ Open',
            'seasonal': '❄️ Seasonally Closed',
            'muddy': '🟡 Muddy',
            'hazardous': '⚠️ Hazardous'
          };

          badge.className = `status-badge status-${newStatus}`;
          badge.textContent = statusLabels[newStatus];

          // Update data attribute for filtering
          row.dataset.status = newStatus;

          // Show success feedback
          this.textContent = '✓ Updated!';
          this.style.background = '#10b981';
          this.style.color = 'white';

          setTimeout(() => {
            this.textContent = originalText;
            this.style.background = '';
            this.style.color = '';
            this.disabled = false;
          }, 2000);
        } else {
          alert('Failed to update trail status');
          this.disabled = false;
          this.textContent = originalText;
        }
      })
      .catch(err => {
        console.error('Update error:', err);
        alert('Error updating trail status');
        this.disabled = false;
        this.textContent = originalText;
      });
    });
  });

  /**
   * Filter trails by area and status
   */
  function filterTrails() {
    const areaFilter = document.getElementById('area-filter').value;
    const statusFilter = document.getElementById('status-filter').value;

    document.querySelectorAll('.trail-row').forEach(row => {
      const rowArea = row.dataset.area;
      const rowStatus = row.dataset.status;

      const areaMatch = !areaFilter || rowArea === areaFilter;
      const statusMatch = !statusFilter || rowStatus === statusFilter;

      if (areaMatch && statusMatch) {
        row.style.display = '';
      } else {
        row.style.display = 'none';
      }
    });
  }

  document.getElementById('area-filter')?.addEventListener('change', filterTrails);
  document.getElementById('status-filter')?.addEventListener('change', filterTrails);
})();
