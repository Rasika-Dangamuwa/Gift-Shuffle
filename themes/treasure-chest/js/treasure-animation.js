/**
 * Treasure Chest Animation Script
 * For Gift Shuffle System
 */

document.addEventListener('DOMContentLoaded', function() {
    // Reference to the animation elements
    const treasureAnimation = document.querySelector('.treasure-animation');
    const chestLid = document.querySelector('.chest-lid');
    const chestGlow = document.querySelector('.chest-glow');
    const prizeElement = document.querySelector('.treasure-prize');
    const progressBar = document.querySelector('.progress-bar');
    
    // Animation state
    let animationComplete = false;
    let animationStarted = false;
    
    // Function to reset animation
    function resetAnimation() {
        // Reset chest
        if (chestLid) {
            chestLid.style.animation = 'none';
            void chestLid.offsetWidth; // Trigger reflow
            chestLid.style.animation = 'lid-open 1.5s ease-in-out forwards 1s';
        }
        
        // Reset glow
        if (chestGlow) {
            chestGlow.style.animation = 'none';
            void chestGlow.offsetWidth; // Trigger reflow
            chestGlow.style.animation = 'glow-pulse 2s ease-in-out infinite alternate, glow-appear 1s ease-out forwards 1.8s';
        }
        
        // Reset prize
        if (prizeElement) {
            prizeElement.style.animation = 'none';
            void prizeElement.offsetWidth; // Trigger reflow
            prizeElement.style.animation = 'prize-appear 0.5s ease-out forwards 2s';
        }
        
        // Reset progress bar
        if (progressBar) {
            progressBar.style.animation = 'none';
            void progressBar.offsetWidth; // Trigger reflow
            progressBar.style.animation = 'progress 3s linear forwards';
        }
        
        // Reset state
        animationComplete = false;
    }
    
    // Function to start animation
    function startAnimation() {
        if (animationStarted) return;
        
        animationStarted = true;
        
        // Show the animation container
        if (treasureAnimation) {
            treasureAnimation.style.opacity = '1';
        }
        
        // Add event listener for animation end
        if (progressBar) {
            progressBar.addEventListener('animationend', function() {
                animationComplete = true;
                
                // Dispatch an event to notify the main app that animation is complete
                const animationCompleteEvent = new CustomEvent('treasureAnimationComplete', {
                    detail: { animationId: 'treasure-chest' }
                });
                document.dispatchEvent(animationCompleteEvent);
                
                // Reset for next play
                setTimeout(() => {
                    animationStarted = false;
                }, 500);
            });
        }
    }
    
    // Add event listener for click to start animation (for testing)
    if (treasureAnimation) {
        treasureAnimation.addEventListener('click', function() {
            if (!animationStarted) {
                startAnimation();
            } else if (animationComplete) {
                resetAnimation();
                startAnimation();
            }
        });
    }
    
    // Listen for play event from the main app
    document.addEventListener('startGiftShuffle', function() {
        resetAnimation();
        startAnimation();
    });
    
    // Add sparkle animation effect
    function createSparkles() {
        const sparkleContainer = document.querySelector('.treasure-background');
        if (!sparkleContainer) return;
        
        for (let i = 0; i < 15; i++) {
            const sparkle = document.createElement('div');
            sparkle.classList.add('background-sparkle');
            
            // Random position
            sparkle.style.left = `${Math.random() * 100}%`;
            sparkle.style.top = `${Math.random() * 100}%`;
            
            // Random size
            const size = 2 + Math.random() * 4;
            sparkle.style.width = `${size}px`;
            sparkle.style.height = `${size}px`;
            
            // Random animation delay
            sparkle.style.animationDelay = `${Math.random() * 3}s`;
            
            sparkleContainer.appendChild(sparkle);
        }
    }
    
    // Create initial sparkles
    createSparkles();
    
    // Add responsive handling for mobile devices
    function handleResponsive() {
        if (window.innerWidth < 480) {
            // Apply mobile-specific adjustments if needed
            treasureAnimation.classList.add('mobile-view');
        } else {
            treasureAnimation.classList.remove('mobile-view');
        }
    }
    
    // Initial check and add listener for resize
    handleResponsive();
    window.addEventListener('resize', handleResponsive);
    
    // Add accessibility enhancements
    if (treasureAnimation) {
        treasureAnimation.setAttribute('aria-label', 'Treasure chest animation for gift reveal');
        treasureAnimation.setAttribute('role', 'img');
    }
});

// Add sound effects if enabled (will be controlled by the main app)
function playChestSound(soundType) {
    // Check if sounds are enabled in the main app
    if (window.giftShuffleSoundEnabled === false) return;
    
    let soundUrl = '';
    
    switch(soundType) {
        case 'open':
            soundUrl = 'sounds/chest-open.mp3';
            break;
        case 'shine':
            soundUrl = 'sounds/sparkle.mp3';
            break;
        case 'win':
            soundUrl = 'sounds/win.mp3';
            break;
    }
    
    if (soundUrl && window.Audio) {
        try {
            const sound = new Audio(soundUrl);
            sound.volume = 0.5; // 50% volume
            sound.play().catch(e => console.log('Sound play prevented by browser policy'));
        } catch (e) {
            console.log('Sound play error:', e);
        }
    }
}

// Export functions for use by the main application
window.treasureTheme = {
    reset: function() {
        const event = new Event('resetTreasureAnimation');
        document.dispatchEvent(event);
    },
    start: function() {
        const event = new Event('startGiftShuffle');
        document.dispatchEvent(event);
        
        // Play sound
        setTimeout(() => playChestSound('open'), 1000);
        setTimeout(() => playChestSound('shine'), 1800);
        setTimeout(() => playChestSound('win'), 2000);
    }
};