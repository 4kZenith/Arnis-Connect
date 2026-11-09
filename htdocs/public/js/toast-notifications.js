function showNotification(message, type = 'success') {
    createToastContainer();
    const container = document.getElementById('toastContainer');
    
    const toastEl = document.createElement('div');
    toastEl.className = `toast-notification ${
        type === 'success' 
            ? 'bg-emerald-500/10 border-emerald-500/30' 
            : 'bg-amber-500/10 border-amber-500/30'
    }`;
    
    const icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
    const color = type === 'success' ? 'text-emerald-400' : 'text-amber-400';
    
    toastEl.innerHTML = `
        <i class="fas fa-${icon} ${color} text-lg"></i>
        <span class="text-white text-sm font-medium">${message}</span>
    `;
    
    container.appendChild(toastEl);
    
    requestAnimationFrame(() => {
        toastEl.classList.add('show');
    });
    
    setTimeout(() => {
        toastEl.classList.remove('show');
        setTimeout(() => toastEl.remove(), 300);
    }, 3000);
}

function createToastContainer() {
    if (document.getElementById('toastContainer')) return;
    
    const container = document.createElement('div');
    container.id = 'toastContainer';
    document.body.appendChild(container);
    
    const style = document.createElement('style');
    style.textContent = `
        #toastContainer {
            position: fixed;
            top: 1.5rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            pointer-events: none;
        }
        .toast-notification {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            border: 1px solid;
            backdrop-filter: blur(10px);
            animation: slideDown 0.3s ease-out forwards;
            opacity: 0;
            pointer-events: auto;
        }
        .toast-notification.show {
            animation: slideDown 0.3s ease-out forwards;
            opacity: 1;
        }
        @keyframes slideDown {
            from {
                transform: translateY(-1rem);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
    `;
    document.head.appendChild(style);
}
