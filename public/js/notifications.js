/**
 * K-Docs - Notifications Module
 * Gestion des notifications en temps réel
 */

const KDocsNotifications = (function() {
    'use strict';

    let config = {
        pollInterval: 30000, // 30 seconds
        maxNotifications: 10,
        basePath: ''
    };

    let state = {
        unreadCount: 0,
        isPolling: false,
        pollTimer: null
    };

    /**
     * Initialize the notifications module
     */
    function init(options = {}) {
        config = { ...config, ...options };

        // Start polling
        startPolling();

        // Listen for visibility changes
        document.addEventListener('visibilitychange', handleVisibilityChange);

        console.log('[Notifications] Initialized');
    }

    /**
     * Start polling for new notifications
     */
    function startPolling() {
        if (state.isPolling) return;

        state.isPolling = true;
        poll();
        state.pollTimer = setInterval(poll, config.pollInterval);
    }

    /**
     * Stop polling
     */
    function stopPolling() {
        state.isPolling = false;
        if (state.pollTimer) {
            clearInterval(state.pollTimer);
            state.pollTimer = null;
        }
    }

    /**
     * Poll for new notifications
     */
    async function poll() {
        try {
            const response = await fetch(`${config.basePath}/api/notifications/count`);
            const data = await response.json();

            if (data.success) {
                const newCount = data.count || 0;

                // Check if we have new notifications
                if (newCount > state.unreadCount) {
                    triggerEvent('newNotifications', { count: newCount - state.unreadCount });
                }

                state.unreadCount = newCount;
                updateBadge(newCount);
            }
        } catch (error) {
            console.error('[Notifications] Poll error:', error);
        }
    }

    /**
     * Fetch unread notifications
     */
    async function fetchUnread(limit = 10) {
        try {
            const response = await fetch(`${config.basePath}/api/notifications/unread?limit=${limit}`);
            const data = await response.json();

            if (data.success) {
                state.unreadCount = data.count || 0;
                return data.notifications || [];
            }
            return [];
        } catch (error) {
            console.error('[Notifications] Fetch error:', error);
            return [];
        }
    }

    /**
     * Mark a notification as read
     */
    async function markAsRead(notificationId) {
        try {
            const response = await fetch(`${config.basePath}/api/notifications/${notificationId}/read`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            const data = await response.json();

            if (data.success) {
                state.unreadCount = Math.max(0, state.unreadCount - 1);
                updateBadge(state.unreadCount);
                triggerEvent('notificationRead', { id: notificationId });
            }

            return data.success;
        } catch (error) {
            console.error('[Notifications] Mark read error:', error);
            return false;
        }
    }

    /**
     * Mark all notifications as read
     */
    async function markAllAsRead() {
        try {
            const response = await fetch(`${config.basePath}/api/notifications/read-all`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            const data = await response.json();

            if (data.success) {
                state.unreadCount = 0;
                updateBadge(0);
                triggerEvent('allNotificationsRead');
            }

            return data.success;
        } catch (error) {
            console.error('[Notifications] Mark all read error:', error);
            return false;
        }
    }

    /**
     * Update the notification badge
     */
    function updateBadge(count) {
        const badge = document.getElementById('notification-badge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }

        // Update page title
        if (count > 0) {
            document.title = `(${count}) ${document.title.replace(/^\(\d+\)\s*/, '')}`;
        } else {
            document.title = document.title.replace(/^\(\d+\)\s*/, '');
        }
    }

    /**
     * Handle visibility change (pause polling when tab is hidden)
     */
    function handleVisibilityChange() {
        if (document.hidden) {
            stopPolling();
        } else {
            startPolling();
            poll(); // Immediate poll when tab becomes visible
        }
    }

    /**
     * Trigger custom event
     */
    function triggerEvent(name, detail = {}) {
        const event = new CustomEvent(`kdocs:notifications:${name}`, { detail });
        document.dispatchEvent(event);
    }

    /**
     * Show a toast notification
     */
    function showToast(message, type = 'info', duration = 3000) {
        const toast = document.createElement('div');
        const bgColors = {
            success: 'bg-green-500',
            error: 'bg-red-500',
            warning: 'bg-orange-500',
            info: 'bg-blue-500'
        };

        toast.className = `fixed bottom-4 right-4 ${bgColors[type] || bgColors.info} text-white px-4 py-2 rounded-lg shadow-lg z-50 transition-all duration-300 transform translate-y-0`;
        toast.textContent = message;
        document.body.appendChild(toast);

        // Animate in
        requestAnimationFrame(() => {
            toast.style.opacity = '1';
        });

        // Remove after duration
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    // Public API
    return {
        init,
        fetchUnread,
        markAsRead,
        markAllAsRead,
        showToast,
        getUnreadCount: () => state.unreadCount,
        startPolling,
        stopPolling
    };
})();

// Auto-initialize if base path is set
document.addEventListener('DOMContentLoaded', function() {
    const basePath = document.body.dataset.basePath || '';
    KDocsNotifications.init({ basePath });
});
