/**
 * MY SHIFT MODULE - Учет рабочего времени
 */

// Состояние
let shiftStatus = 'offline'; // offline, working, break
let shiftTimer = '00:00:00';
let shiftStart = null;
let breakStart = null;
let breakTime = 0;
let workedTime = 0;
let timerInterval = null;

// История смен
let shiftHistory = [];

// График на неделю
let weekSchedule = [];

// ============================================
// ИНИЦИАЛИЗАЦИЯ
// ============================================

function initMyShift() {
    loadShiftData();
    startTimer();
    generateWeekSchedule();
}

// ============================================
// ФУНКЦИИ
// ============================================

// Начать смену
function startShift() {
    shiftStatus = 'working';
    shiftStart = new Date().toLocaleTimeString('ru-RU', {hour: '2-digit', minute:'2-digit'});
    saveShiftData();
    showToast('Смена началась', 'success');
}

// Начать перерыв
function startBreak() {
    shiftStatus = 'break';
    breakStart = Date.now();
    saveShiftData();
    showToast('Перерыв начался', 'info');
}

// Вернуться с перерыва
function endBreak() {
    if (breakStart) {
        breakTime += Math.floor((Date.now() - breakStart) / 60000);
        breakStart = null;
    }
    shiftStatus = 'working';
    saveShiftData();
    showToast('С возвращением!', 'success');
}

// Закончить смену
function endShift() {
    if (shiftStatus === 'working') {
        workedTime += 1; // Час за смену (упрощенно)
    }
    
    shiftHistory.unshift({
        id: Date.now(),
        date: new Date().toLocaleDateString('ru-RU'),
        start: shiftStart,
        end: new Date().toLocaleTimeString('ru-RU', {hour: '2-digit', minute:'2-digit'}),
        break: breakTime + ' мин',
        worked: workedTime + ' ч',
        status: 'completed'
    });
    
    // Сброс
    shiftStatus = 'offline';
    shiftStart = null;
    breakTime = 0;
    workedTime = 0;
    
    saveShiftData();
    showToast('Смена завершена', 'success');
}

// ============================================
// ТАЙМЕР
// ============================================

function startTimer() {
    if (timerInterval) clearInterval(timerInterval);
    
    timerInterval = setInterval(() => {
        if (shiftStatus === 'working') {
            updateTimer();
        }
    }, 1000);
}

function updateTimer() {
    const now = new Date();
    const start = shiftStart ? new Date().setHours(...shiftStart.split(':')) : now;
    const diff = Math.floor((now - start) / 1000);
    
    const hours = Math.floor(diff / 3600);
    const minutes = Math.floor((diff % 3600) / 60);
    const seconds = diff % 60;
    
    shiftTimer = 
        String(hours).padStart(2, '0') + ':' +
        String(minutes).padStart(2, '0') + ':' +
        String(seconds).padStart(2, '0');
}

// ============================================
// СОХРАНЕНИЕ
// ============================================

function saveShiftData() {
    localStorage.setItem('shiftStatus', shiftStatus);
    localStorage.setItem('shiftStart', shiftStart || '');
    localStorage.setItem('breakTime', breakTime.toString());
    localStorage.setItem('workedTime', workedTime.toString());
    localStorage.setItem('shiftHistory', JSON.stringify(shiftHistory));
}

function loadShiftData() {
    shiftStatus = localStorage.getItem('shiftStatus') || 'offline';
    shiftStart = localStorage.getItem('shiftStart') || null;
    breakTime = parseInt(localStorage.getItem('breakTime') || '0');
    workedTime = parseInt(localStorage.getItem('workedTime') || '0');
    shiftHistory = JSON.parse(localStorage.getItem('shiftHistory') || '[]');
}

// ============================================
// ГРАФИК
// ============================================

function generateWeekSchedule() {
    const days = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    const today = new Date();
    
    weekSchedule = days.map((day, index) => {
        const dayDate = new Date(today);
        dayDate.setDate(today.getDate() - today.getDay() + index + 1);
        
        return {
            date: dayDate.toLocaleDateString('ru-RU'),
            name: day,
            isToday: index === today.getDay() - 1,
            status: index < today.getDay() - 1 ? 'Отработан' : 'План'
        };
    });
}

// ============================================
// ЭКСПОРТ
// ============================================

if (typeof window !== 'undefined') {
    window.initMyShift = initMyShift;
    window.startShift = startShift;
    window.startBreak = startBreak;
    window.endBreak = endBreak;
    window.endShift = endShift;
}
