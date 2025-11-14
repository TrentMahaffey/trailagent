/**
 * Public outstanding maintenance dashboard
 */
(function() {
  const { root, nonce, isAdmin } = TCR_OUTSTANDING;

  function decodeEntities(s) {
    const txt = document.createElement('textarea');
    txt.innerHTML = s ?? "";
    return txt.value;
  }

  function loadStats() {
    fetch(`${root}outstanding/stats`, {
      headers: { 'X-WP-Nonce': nonce }
    })
    .then(r => r.json())
    .then(stats => {
      document.getElementById('outstanding-count').textContent = stats.active;
      document.getElementById('resolved-count').textContent = stats.resolved_this_month;
    })
    .catch(err => console.error('Error loading stats:', err));
  }

  function loadItems() {
    const container = document.getElementById('tcr-maintenance-items');
    const noItems = document.getElementById('tcr-no-items');

    container.innerHTML = '<div class="tcr-loading">Loading maintenance items...</div>';

    fetch(`${root}outstanding/photos?filter=outstanding`, {
      headers: { 'X-WP-Nonce': nonce }
    })
    .then(r => r.json())
    .then(items => {
      if (!items || items.length === 0) {
        container.style.display = 'none';
        noItems.style.display = 'block';
        return;
      }

      container.style.display = 'grid';
      noItems.style.display = 'none';

      // Store photo data for modals
      window.photoData = window.photoData || {};

      container.innerHTML = items.map(item => {
        // Store photo data
        window.photoData[item.id] = {
          imageUrl: item.image_url,
          trailName: item.trail_name,
          caption: item.caption,
          workDate: item.work_date,
          condComment: item.cond_comment,
          gpsLat: item.gps_lat,
          gpsLng: item.gps_lng,
          userName: item.user_name
        };

        return `
        <div class="tcr-maintenance-card" data-id="${item.id}">
          <div class="maintenance-image">
            <img src="${item.image_url}" alt="${decodeEntities(item.caption || 'Trail maintenance needed')}">
            ${item.gps_lat && item.gps_lng ? `
              <a href="https://www.google.com/maps?q=${item.gps_lat},${item.gps_lng}" target="_blank" class="gps-link">
                📍 View on Map
              </a>
            ` : ''}
          </div>
          <div class="maintenance-details">
            <h3 class="maintenance-trail">${decodeEntities(item.trail_name)}</h3>
            <div class="maintenance-date">Reported: ${item.work_date || 'Unknown date'}</div>
            ${item.caption ? `<div class="maintenance-caption"><strong>Photo Caption:</strong><br>${decodeEntities(item.caption)}</div>` : ''}
            ${item.cond_comment ? `<div class="maintenance-comment"><strong>Conditions:</strong><br>${decodeEntities(item.cond_comment)}</div>` : ''}
            <button class="resolve-btn" data-id="${item.id}">
              ✓ Mark as Resolved
            </button>
          </div>
        </div>
        `;
      }).join('');

      // Attach resolve handlers
      document.querySelectorAll('.resolve-btn').forEach(btn => {
        btn.addEventListener('click', resolveItem);
      });
    })
    .catch(err => {
      container.innerHTML = '<div class="tcr-error">Error loading maintenance items.</div>';
      console.error(err);
    });
  }

  function showResolutionModal(itemId, btn) {
    const photoInfo = window.photoData[itemId];

    // Create modal
    const modal = document.createElement('div');
    modal.className = 'tcr-modal';
    modal.innerHTML = `
      <div class="tcr-modal-content">
        <h2>Mark as Resolved</h2>
        <p>Document how this issue was resolved:</p>

        ${photoInfo ? `
          <div class="modal-photo-preview">
            <img src="${photoInfo.imageUrl}" alt="Trail photo">
            <div class="modal-photo-info">
              <strong>${decodeEntities(photoInfo.trailName)}</strong>
              ${photoInfo.caption ? `<p class="photo-caption">${decodeEntities(photoInfo.caption)}</p>` : ''}
              <div class="photo-meta">
                ${photoInfo.userName ? `<span>👤 ${decodeEntities(photoInfo.userName)}</span>` : ''}
                ${photoInfo.workDate ? `<span>📅 ${photoInfo.workDate}</span>` : ''}
              </div>
              ${photoInfo.condComment ? `<div class="photo-conditions">💬 ${decodeEntities(photoInfo.condComment)}</div>` : ''}
              ${photoInfo.gpsLat && photoInfo.gpsLng ? `
                <div class="photo-location">
                  <a href="https://www.google.com/maps?q=${photoInfo.gpsLat},${photoInfo.gpsLng}" target="_blank">
                    📍 View on Map
                  </a>
                </div>
              ` : ''}
            </div>
          </div>
        ` : ''}

        <label for="resolution-notes">
          <strong>Resolution Notes:</strong>
          <textarea id="resolution-notes" rows="4" placeholder="What work was completed to resolve this issue?"></textarea>
        </label>

        <label for="resolution-date">
          <strong>Resolution Date:</strong>
          <input type="date" id="resolution-date" value="${new Date().toISOString().split('T')[0]}">
        </label>

        <div class="modal-actions">
          <button class="modal-btn cancel">Cancel</button>
          <button class="modal-btn submit">Mark as Resolved</button>
        </div>
      </div>
    `;

    document.body.appendChild(modal);

    const originalText = btn.textContent;

    // Handle cancel
    modal.querySelector('.cancel').addEventListener('click', () => {
      modal.remove();
    });

    // Handle submit
    modal.querySelector('.submit').addEventListener('click', () => {
      const notes = document.getElementById('resolution-notes').value;
      const date = document.getElementById('resolution-date').value;

      if (!notes.trim()) {
        alert('Please add resolution notes');
        return;
      }

      btn.disabled = true;
      btn.textContent = 'Resolving...';

      fetch(`${root}outstanding/resolve/${itemId}`, {
        method: 'POST',
        headers: {
          'X-WP-Nonce': nonce,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          notes: notes,
          date: date
        })
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          modal.remove();
          // Remove the card with animation
          const card = btn.closest('.tcr-maintenance-card');
          card.style.opacity = '0';
          card.style.transform = 'scale(0.9)';
          setTimeout(() => {
            loadItems();
            loadStats();
          }, 300);
        } else {
          alert('Failed to resolve item');
          btn.disabled = false;
          btn.textContent = originalText;
        }
      })
      .catch(err => {
        console.error(err);
        alert('Error resolving item');
        btn.disabled = false;
        btn.textContent = originalText;
      });
    });

    // Close on background click
    modal.addEventListener('click', (e) => {
      if (e.target === modal) {
        modal.remove();
      }
    });
  }

  function resolveItem(e) {
    const btn = e.target;
    const itemId = btn.dataset.id;

    showResolutionModal(itemId, btn);
  }

  // Initialize on load
  document.addEventListener('DOMContentLoaded', () => {
    loadStats();
    loadItems();
  });
})();
