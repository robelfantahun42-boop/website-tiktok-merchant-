// Task Management JavaScript
class TaskManager {
    constructor() {
        this.init();
    }

    init() {
        this.showTopupModal();
        this.setupEventListeners();
        this.setupFormHandlers();
        this.setupSmoothScrolling();
    }

    // Show modal on page load if top-up task exists
    showTopupModal() {
        const modal = document.getElementById('topupModal');
        const mainContent = document.getElementById('main-content');
        
        if (modal) {
            // Show modal and blur background after a short delay
            setTimeout(() => {
                this.openModal(modal, mainContent);
            }, 1000);
        }
    }

    openModal(modal, mainContent) {
        modal.style.display = 'flex';
        setTimeout(() => {
            modal.style.opacity = '1';
            if (mainContent) {
                mainContent.classList.add('blur-background');
            }
        }, 10);
    }

    closeModal() {
        const modal = document.getElementById('topupModal');
        const mainContent = document.getElementById('main-content');
        
        if (modal) {
            modal.style.opacity = '0';
            setTimeout(() => {
                modal.style.display = 'none';
                if (mainContent) {
                    mainContent.classList.remove('blur-background');
                }
            }, 300);
        }
    }

    // Setup event listeners
    setupEventListeners() {
        // Close modal when clicking outside
        document.addEventListener('click', (event) => {
            const modal = document.getElementById('topupModal');
            if (event.target === modal) {
                this.closeModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.closeModal();
            }
        });

        // Prevent form submission if balance is insufficient
        this.setupFormValidation();
    }

    setupFormValidation() {
        const completeButtons = document.querySelectorAll('button[name="complete_task"]');
        completeButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                const modal = document.getElementById('topupModal');
                if (modal && button.closest('#topupModal') && button.disabled) {
                    e.preventDefault();
                    this.showNotification('Please add funds to your account to complete this task.', 'error');
                }
            });
        });
    }

    setupFormHandlers() {
        // Add loading states for buttons
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', (e) => {
                const submitButton = form.querySelector('button[type="submit"]');
                if (submitButton && !submitButton.disabled) {
                    this.showButtonLoading(submitButton);
                }
            });
        });
    }

    showButtonLoading(button) {
        const originalText = button.innerHTML;
        button.innerHTML = `
            <span class="loading-spinner"></span>
            Processing...
        `;
        button.disabled = true;

        // Add loading spinner styles
        this.addLoadingSpinnerStyles();

        // Revert after 5 seconds if still on same page (fallback)
        setTimeout(() => {
            if (button.disabled) {
                button.innerHTML = originalText;
                button.disabled = false;
                this.showNotification('Request timed out. Please try again.', 'warning');
            }
        }, 5000);
    }

    addLoadingSpinnerStyles() {
        if (!document.querySelector('#loading-spinner-styles')) {
            const styles = `
                .loading-spinner {
                    display: inline-block;
                    width: 16px;
                    height: 16px;
                    border: 2px solid #ffffff;
                    border-radius: 50%;
                    border-top-color: transparent;
                    animation: spin 1s ease-in-out infinite;
                    margin-right: 8px;
                }
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
            `;
            const styleSheet = document.createElement('style');
            styleSheet.id = 'loading-spinner-styles';
            styleSheet.textContent = styles;
            document.head.appendChild(styleSheet);
        }
    }

    setupSmoothScrolling() {
        // Smooth scroll to top when completing a task
        const forms = document.querySelectorAll('form[method="POST"]');
        forms.forEach(form => {
            form.addEventListener('submit', () => {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
        });
    }

    showNotification(message, type = 'info') {
        // Remove existing notification
        const existingNotification = document.querySelector('.task-notification');
        if (existingNotification) {
            existingNotification.remove();
        }

        // Create notification element
        const notification = document.createElement('div');
        notification.className = `task-notification task-notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <span class="notification-message">${message}</span>
                <button class="notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
            </div>
        `;

        // Add styles if not already added
        this.addNotificationStyles();

        // Add to page
        document.body.appendChild(notification);

        // Show notification
        setTimeout(() => {
            notification.classList.add('show');
        }, 10);

        // Auto remove after 5 seconds
        setTimeout(() => {
            if (notification.parentElement) {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentElement) {
                        notification.remove();
                    }
                }, 300);
            }
        }, 5000);
    }

    addNotificationStyles() {
        if (!document.querySelector('#notification-styles')) {
            const styles = `
                .task-notification {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: white;
                    padding: 0;
                    border-radius: 8px;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                    z-index: 10000;
                    transform: translateX(400px);
                    transition: transform 0.3s ease;
                    max-width: 400px;
                    border-left: 4px solid #4299e1;
                }
                .task-notification.show {
                    transform: translateX(0);
                }
                .task-notification-error {
                    border-left-color: #e53e3e;
                }
                .task-notification-warning {
                    border-left-color: #ed8936;
                }
                .task-notification-success {
                    border-left-color: #38a169;
                }
                .notification-content {
                    padding: 1rem;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                }
                .notification-message {
                    flex: 1;
                    margin-right: 1rem;
                    font-weight: 500;
                }
                .notification-close {
                    background: none;
                    border: none;
                    font-size: 1.5rem;
                    cursor: pointer;
                    color: #718096;
                    padding: 0;
                    width: 24px;
                    height: 24px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .notification-close:hover {
                    color: #2d3748;
                }
            `;
            const styleSheet = document.createElement('style');
            styleSheet.id = 'notification-styles';
            styleSheet.textContent = styles;
            document.head.appendChild(styleSheet);
        }
    }

    // Utility function to format currency
    formatCurrency(amount) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(amount);
    }

    // Utility function to calculate net gain
    calculateNetGain(reward, cost) {
        return reward - cost;
    }
}

// Initialize Task Manager when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.taskManager = new TaskManager();
});

// Global function for modal close (for onclick handlers)
function closeModal() {
    if (window.taskManager) {
        window.taskManager.closeModal();
    }
}

// Add some interactive effects for task cards
document.addEventListener('DOMContentLoaded', function() {
    const taskCards = document.querySelectorAll('.task-card');
    
    taskCards.forEach(card => {
        // Add click effect
        card.addEventListener('click', function(e) {
            if (!e.target.closest('button') && !e.target.closest('a')) {
                this.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    this.style.transform = '';
                }, 150);
            }
        });

        // Add hover delay for performance
        let hoverTimer;
        card.addEventListener('mouseenter', function() {
            clearTimeout(hoverTimer);
            hoverTimer = setTimeout(() => {
                this.classList.add('hover-active');
            }, 50);
        });

        card.addEventListener('mouseleave', function() {
            clearTimeout(hoverTimer);
            this.classList.remove('hover-active');
        });
    });
});

// Add CSS for hover-active class
const hoverStyles = `
    .task-card.hover-active {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
`;
if (!document.querySelector('#hover-styles')) {
    const styleSheet = document.createElement('style');
    styleSheet.id = 'hover-styles';
    styleSheet.textContent = hoverStyles;
    document.head.appendChild(styleSheet);
}