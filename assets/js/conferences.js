/**
 * CONFERENCES MODULE - Видеоконференции
 */

// Состояние
let conferences = [];
let conferenceModalOpen = false;
let currentConference = null;
let lastCreatedConference = null;
let localStream = null;
let audioEnabled = true;
let videoEnabled = true;
let inviteModalOpen = false;
let inviteSearch = '';
let inviteSelected = [];
let availableUsers = [];

// ============================================
// ИНИЦИАЛИЗАЦИЯ
// ============================================

function showToastSafe(message, type = 'info') {
    try {
        const alpineRoot = document.querySelector('[x-data]');
        const alpineComponent = window.Alpine?.$data?.(alpineRoot);
        if (alpineComponent && typeof alpineComponent.showToast === 'function') {
            alpineComponent.showToast(message, type);
            return;
        }
    } catch {
        // ignore
    }

    if (typeof window.showToast === 'function') {
        window.showToast(message, type);
        return;
    }

    console.log(`[toast:${type}]`, message);
}

function initConferences() {
    loadConferences();
}

function getInitials(name) {
    const s = String(name || '').trim();
    if (!s) return '?';
    const parts = s.split(/\s+/).filter(Boolean);
    const first = parts[0]?.[0] || '';
    const last = (parts.length > 1 ? parts[parts.length - 1]?.[0] : '') || '';
    return (first + last).toUpperCase();
}

function guestJoinUrl(roomId) {
    return `${window.location.origin}/conference-join.html?room=${encodeURIComponent(roomId)}`;
}

async function copyToClipboard(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        return false;
    }
}

async function copyGuestLink(roomId) {
    const ok = await copyToClipboard(guestJoinUrl(roomId));
    showToastSafe(ok ? 'Ссылка скопирована' : 'Не удалось скопировать', ok ? 'success' : 'error');
}

async function copyPin(pin) {
    const ok = await copyToClipboard(String(pin || ''));
    showToastSafe(ok ? 'PIN скопирован' : 'Не удалось скопировать', ok ? 'success' : 'error');
}

function openInviteModal(conf) {
    currentConference = conf;
    inviteModalOpen = true;
    inviteSearch = '';
    inviteSelected = [];
    if (!availableUsers.length) loadUsersForInvite();
}

function closeInviteModal() {
    inviteModalOpen = false;
    inviteSearch = '';
    inviteSelected = [];
}

async function loadUsersForInvite() {
    try {
        const data = await apiGet('users');
        if (data?.success) {
            availableUsers = (data.data || []).filter(u => u && u.id);
        }
    } catch (e) {
        console.error('Ошибка загрузки пользователей:', e);
    }
}

function filteredInviteUsers() {
    const q = String(inviteSearch || '').trim().toLowerCase();
    if (!q) return availableUsers;
    return availableUsers.filter(u => {
        const text = `${u.full_name || ''} ${u.login || ''}`.toLowerCase();
        return text.includes(q);
    });
}

function toggleInviteUser(user) {
    const id = String(user?.id);
    const idx = inviteSelected.findIndex(x => String(x.id) === id);
    if (idx >= 0) inviteSelected.splice(idx, 1);
    else inviteSelected.push({ id: user.id, full_name: user.full_name, avatar: user.avatar });
}

async function sendInvites() {
    try {
        if (!currentConference?.id) return;
        if (!inviteSelected.length) {
            showToastSafe('Выберите сотрудников', 'info');
            return;
        }

        const requests = inviteSelected.map(u => apiPost(`conferences/${currentConference.id}/participants`, {
            user_id: u.id,
            role: 'participant'
        }));

        const results = await Promise.all(requests);
        const okCount = results.filter(r => r && r.success).length;
        showToastSafe(`Приглашено: ${okCount}`, okCount ? 'success' : 'error');
        closeInviteModal();
    } catch (e) {
        console.error('Ошибка приглашения:', e);
        showToastSafe('Ошибка приглашения', 'error');
    }
}

function conferencesApiUrl(endpoint) {
    const token = getToken() || '';
    const encodedEndpoint = encodeURIComponent(endpoint);
    const encodedToken = encodeURIComponent(token);

    let url = `api/index.php?endpoint=${encodedEndpoint}`;
    if (token) url += `&token=${encodedToken}`;

    return url;
}

function conferencesAuthHeaders(extraHeaders = {}) {
    const token = getToken() || '';
    return {
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...extraHeaders,
    };
}

// ============================================
// ФУНКЦИИ
// ============================================

// Загрузить конференции
async function loadConferences() {
    try {
        const response = await fetch(conferencesApiUrl('conferences'), {
            headers: conferencesAuthHeaders(),
        });
        const data = await response.json();
        
        if (data.success) {
            conferences = data.data || [];
        }
    } catch (error) {
        console.error('Ошибка загрузки конференций:', error);
    }
}

// Создать конференцию
async function createConference() {
    const title = prompt('Название встречи:');
    if (!title) return;
    
    const description = prompt('Описание (необязательно):') || '';
    
    try {
        const response = await fetch(conferencesApiUrl('conferences'), {
            method: 'POST',
            headers: conferencesAuthHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({ title, description })
        });
        const data = await response.json();
        
        if (data.success) {
            lastCreatedConference = data.data || null;
            showToastSafe('Встреча создана', 'success');
            loadConferences();
        }
    } catch (error) {
        console.error('Ошибка создания:', error);
        showToastSafe('Ошибка создания встречи', 'error');
    }
}

// Войти в конференцию
async function joinConference(conf) {
    // Перенаправляем на страницу конференции (Zoom-style)
    // Сначала получаем participant_id
    try {
        const response = await fetch(`api/conferences/${conf.id}/participants`, {
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        const data = await response.json();
        
        if (data.success) {
            const myParticipant = data.data.find(p => p.user_id === getCurrentUser()?.id);
            if (myParticipant) {
                window.location.href = `conference-room.html?room=${conf.room_id}&participant=${myParticipant.id}`;
                return;
            }
        }
        
        // Если участника нет, создаём запрос на присоединение
        const joinResponse = await fetch(`api/conferences/${conf.id}/join-request`, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getToken()}`
            },
            body: JSON.stringify({
                guest_pin: '' // Для зарегистрированных пользователей PIN не требуется
            })
        });
        
        const joinData = await joinResponse.json();
        if (joinData.success) {
            window.location.href = `conference-room.html?room=${conf.room_id}&participant=${joinData.data.participant_id}`;
        }
    } catch (error) {
        console.error('Ошибка входа в конференцию:', error);
        showToastSafe('Ошибка подключения к конференции', 'error');
    }
}

// Переключить аудио
function toggleAudio() {
    if (localStream) {
        const audioTrack = localStream.getAudioTracks()[0];
        if (audioTrack) {
            audioEnabled = !audioEnabled;
            audioTrack.enabled = audioEnabled;
        }
    }
}

// Переключить видео
function toggleVideo() {
    if (localStream) {
        const videoTrack = localStream.getVideoTracks()[0];
        if (videoTrack) {
            videoEnabled = !videoEnabled;
            videoTrack.enabled = videoEnabled;
        }
    }
}

// Выйти из конференции
function leaveConference() {
    // Остановка стрима
    if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
        localStream = null;
    }
    
    conferenceModalOpen = false;
    currentConference = null;
}

// ============================================
// ЭКСПОРТ
// ============================================

if (typeof window !== 'undefined') {
    window.initConferences = initConferences;
    window.loadConferences = loadConferences;
    window.createConference = createConference;
    window.joinConference = joinConference;
    window.copyGuestLink = copyGuestLink;
    window.copyPin = copyPin;
    window.guestJoinUrl = guestJoinUrl;
    window.openInviteModal = openInviteModal;
    window.closeInviteModal = closeInviteModal;
    window.filteredInviteUsers = filteredInviteUsers;
    window.toggleInviteUser = toggleInviteUser;
    window.sendInvites = sendInvites;
    window.toggleAudio = toggleAudio;
    window.toggleVideo = toggleVideo;
    window.leaveConference = leaveConference;
    window.getInitials = getInitials;

    window.conferencesState = () => ({
        get conferences() { return conferences; },
        get conferenceModalOpen() { return conferenceModalOpen; },
        get currentConference() { return currentConference; },
        get audioEnabled() { return audioEnabled; },
        get videoEnabled() { return videoEnabled; },
        get lastCreatedConference() { return lastCreatedConference; },
        get inviteModalOpen() { return inviteModalOpen; },
        get inviteSearch() { return inviteSearch; },
        get inviteSelected() { return inviteSelected; },
        set inviteSearch(v) { inviteSearch = v; },
    });
}
