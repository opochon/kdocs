<?php
/**
 * Widget: Dropdown notifications pour le header
 * Affiche une icône cloche avec badge et dropdown des notifications récentes
 */

use KDocs\Services\NotificationService;
use KDocs\Services\TaskUnifiedService;

$user = $user ?? null;
$notificationCount = 0;
$taskCount = 0;
$hasUrgent = false;

if ($user) {
    try {
        $notificationService = new NotificationService();
        $taskService = new TaskUnifiedService();

        $notifCounts = $notificationService->getUnreadCountByPriority($user['id']);
        $notificationCount = $notifCounts['total'];
        $hasUrgent = ($notifCounts['urgent'] ?? 0) > 0 || ($notifCounts['high'] ?? 0) > 0;

        $taskCounts = $taskService->getTaskCounts($user['id']);
        $taskCount = $taskCounts['total'];
    } catch (\Exception $e) {
        // Ignorer si tables non créées
    }
}

$totalBadge = $notificationCount + $taskCount;
?>

<?php if ($user): ?>
<div class="relative" id="notifications-dropdown">
    <!-- Bell button -->
    <button id="notifications-toggle"
            class="relative p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors"
            title="Notifications">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>

        <!-- Badge -->
        <?php if ($totalBadge > 0): ?>
        <span id="notification-badge"
              class="absolute -top-0.5 -right-0.5 flex items-center justify-center min-w-[18px] h-[18px] px-1 text-xs font-bold text-white rounded-full <?= $hasUrgent ? 'bg-red-500 animate-pulse' : 'bg-blue-500' ?>">
            <?= $totalBadge > 99 ? '99+' : $totalBadge ?>
        </span>
        <?php endif; ?>
    </button>

    <!-- Dropdown -->
    <div id="notifications-menu"
         class="hidden absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-lg border border-gray-200 z-50">
        <!-- Header -->
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900">Notifications</h3>
            <div class="flex items-center gap-2">
                <?php if ($notificationCount > 0): ?>
                <button onclick="markAllNotificationsRead()"
                        class="text-xs text-blue-600 hover:text-blue-800">
                    Tout marquer lu
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Content -->
        <div id="notifications-list" class="max-h-96 overflow-y-auto">
            <!-- Tâches urgentes -->
            <?php if ($taskCount > 0): ?>
            <div class="px-4 py-2 bg-gray-50 border-b border-gray-100">
                <a href="<?= url('/mes-taches') ?>" class="flex items-center justify-between text-sm text-gray-700 hover:text-blue-600">
                    <span class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                        <span><?= $taskCount ?> tâche(s) en attente</span>
                    </span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>
            <?php endif; ?>

            <!-- Loading state -->
            <div id="notifications-loading" class="hidden px-4 py-8 text-center">
                <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600"></div>
            </div>

            <!-- Empty state -->
            <div id="notifications-empty" class="<?= $notificationCount === 0 ? '' : 'hidden' ?> px-4 py-8 text-center text-gray-500 text-sm">
                Aucune notification
            </div>

            <!-- Notifications will be loaded here via JS -->
            <div id="notifications-items">
                <!-- Loaded dynamically -->
            </div>
        </div>

        <!-- Footer -->
        <div class="px-4 py-2 border-t border-gray-100 bg-gray-50">
            <a href="<?= url('/mes-taches') ?>"
               class="block text-center text-sm text-blue-600 hover:text-blue-800">
                Voir toutes les tâches
            </a>
        </div>
    </div>
</div>

<script>
(function() {
    const toggle = document.getElementById('notifications-toggle');
    const menu = document.getElementById('notifications-menu');
    const list = document.getElementById('notifications-items');
    const loading = document.getElementById('notifications-loading');
    const empty = document.getElementById('notifications-empty');
    const badge = document.getElementById('notification-badge');

    let isOpen = false;
    let lastFetch = 0;
    const CACHE_TTL = 30000; // 30 seconds

    // Toggle dropdown
    toggle?.addEventListener('click', function(e) {
        e.stopPropagation();
        isOpen = !isOpen;
        menu.classList.toggle('hidden', !isOpen);

        if (isOpen) {
            loadNotifications();
        }
    });

    // Close on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#notifications-dropdown')) {
            menu?.classList.add('hidden');
            isOpen = false;
        }
    });

    // Load notifications
    async function loadNotifications(force = false) {
        const now = Date.now();
        if (!force && (now - lastFetch) < CACHE_TTL) {
            return; // Use cached data
        }

        loading?.classList.remove('hidden');
        empty?.classList.add('hidden');

        try {
            const response = await fetch('<?= url('/api/notifications/unread') ?>?limit=10');
            const data = await response.json();

            lastFetch = Date.now();
            loading?.classList.add('hidden');

            if (data.success && data.notifications.length > 0) {
                renderNotifications(data.notifications);
                empty?.classList.add('hidden');
            } else {
                list.innerHTML = '';
                empty?.classList.remove('hidden');
            }

            // Update badge
            updateBadge(data.count || 0);

        } catch (error) {
            console.error('Error loading notifications:', error);
            loading?.classList.add('hidden');
        }
    }

    // Render notifications
    function renderNotifications(notifications) {
        list.innerHTML = notifications.map(n => `
            <div class="notification-item px-4 py-3 border-b border-gray-100 hover:bg-gray-50 cursor-pointer ${n.is_read ? 'opacity-60' : ''}"
                 onclick="handleNotificationClick(${n.id}, '${escapeHtml(n.action_url || n.link || '')}')">
                <div class="flex items-start gap-3">
                    <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center ${getNotificationBgClass(n.type, n.priority)}">
                        ${getNotificationIcon(n.type)}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 truncate">${escapeHtml(n.title)}</p>
                        ${n.message ? `<p class="text-xs text-gray-500 truncate mt-0.5">${escapeHtml(n.message)}</p>` : ''}
                        <p class="text-xs text-gray-400 mt-1">${formatDate(n.created_at)}</p>
                    </div>
                    ${n.priority === 'urgent' || n.priority === 'high' ? '<span class="w-2 h-2 bg-red-500 rounded-full"></span>' : ''}
                </div>
            </div>
        `).join('');
    }

    // Get notification background class
    function getNotificationBgClass(type, priority) {
        if (priority === 'urgent') return 'bg-red-100 text-red-600';
        if (priority === 'high') return 'bg-orange-100 text-orange-600';

        const colors = {
            'validation_pending': 'bg-blue-100 text-blue-600',
            'validation_approved': 'bg-green-100 text-green-600',
            'validation_rejected': 'bg-red-100 text-red-600',
            'note_received': 'bg-purple-100 text-purple-600',
            'note_action_required': 'bg-orange-100 text-orange-600',
            'task_assigned': 'bg-indigo-100 text-indigo-600',
            'default': 'bg-gray-100 text-gray-600'
        };
        return colors[type] || colors['default'];
    }

    // Get notification icon
    function getNotificationIcon(type) {
        const icons = {
            'validation_pending': '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            'validation_approved': '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>',
            'validation_rejected': '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>',
            'note_received': '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
            'default': '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>'
        };
        return icons[type] || icons['default'];
    }

    // Format date
    function formatDate(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diff = (now - date) / 1000;

        if (diff < 60) return 'A l\'instant';
        if (diff < 3600) return Math.floor(diff / 60) + ' min';
        if (diff < 86400) return Math.floor(diff / 3600) + ' h';
        return date.toLocaleDateString('fr-FR');
    }

    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    // Update badge
    function updateBadge(count) {
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }
    }

    // Handle notification click
    window.handleNotificationClick = async function(id, url) {
        try {
            await fetch(`<?= url('/api/notifications/') ?>${id}/read`, { method: 'POST' });
        } catch (e) {}

        if (url) {
            window.location.href = url;
        }
    };

    // Mark all as read
    window.markAllNotificationsRead = async function() {
        try {
            await fetch('<?= url('/api/notifications/read-all') ?>', { method: 'POST' });
            loadNotifications(true);
            updateBadge(0);
        } catch (e) {
            console.error('Error marking all as read:', e);
        }
    };

    // Poll for new notifications every 30s
    setInterval(() => {
        if (!isOpen) {
            fetch('<?= url('/api/notifications/count') ?>')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        updateBadge(data.count);
                    }
                })
                .catch(() => {});
        }
    }, 30000);
})();
</script>
<?php endif; ?>
