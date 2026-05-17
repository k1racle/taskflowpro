/**
 * Conferences Module - Видеоконференции (Zoom-like)
 * Добавляется в app_combined.js
 */

// ============================================
// ДАННЫЕ ДЛЯ КОНФЕРЕНЦИЙ
// ============================================

// Добавить в объект данных app():
/*
conferences: [],                    // Список конференций
activeConference: null,             // Активная конференция
conferenceParticipants: [],         // Участники конференции
conferenceRequests: [],             // Запросы на присоединение
conferenceChat: [],                 // Чат конференции
conferenceChatMessage: '',          // Новое сообщение
isInConference: false,              // В конференции ли сейчас
localStream: null,                  // Локальный видеопоток
remoteStreams: {},                  // Удалённые видеопотоки
conferenceSettings: {               // Настройки конференции
    audioEnabled: true,
    videoEnabled: true,
    screenSharing: false
}
*/

// ============================================
// ФУНКЦИИ ДЛЯ КОНФЕРЕНЦИЙ
// ============================================

// Загрузка списка конференций
async loadConferences() {
    try {
        const data = await apiGet('conferences');
        if (data.success) {
            this.conferences = data.data;
        }
    } catch (error) {
        console.error('Ошибка загрузки конференций:', error);
    }
},

// Создание конференции
async createConference(title, description) {
    try {
        const data = await apiPost('conferences', {
            title: title,
            description: description
        });
        
        if (data.success) {
            await this.loadConferences();
            return data.data; // {id, room_id, join_url}
        }
    } catch (error) {
        console.error('Ошибка создания конференции:', error);
    }
    return null;
},

// Начало конференции
async startConference(conferenceId) {
    try {
        const data = await apiPost(`conferences/${conferenceId}/start`);
        if (data.success) {
            await this.loadConferences();
            return true;
        }
    } catch (error) {
        console.error('Ошибка начала конференции:', error);
    }
    return false;
},

// Завершение конференции
async endConference(conferenceId) {
    try {
        const data = await apiPost(`conferences/${conferenceId}/end`);
        if (data.success) {
            await this.loadConferences();
            return true;
        }
    } catch (error) {
        console.error('Ошибка завершения конференции:', error);
    }
    return false;
},

// Запрос на присоединение к конференции
async requestJoinConference(conferenceId, guestName, guestEmail) {
    try {
        const data = await apiPost(`conferences/${conferenceId}/join-request`, {
            guest_name: guestName,
            guest_email: guestEmail
        });
        
        if (data.success) {
            return data.data; // {participant_id, status}
        }
    } catch (error) {
        console.error('Ошибка запроса на присоединение:', error);
    }
    return null;
},

// Получение запросов на присоединение (для хоста)
async getConferenceJoinRequests(conferenceId) {
    try {
        const data = await apiGet(`conferences/${conferenceId}/join-requests`);
        if (data.success) {
            this.conferenceRequests = data.data;
            return data.data;
        }
    } catch (error) {
        console.error('Ошибка получения запросов:', error);
    }
    return [];
},

// Одобрение/отклонение запроса
async reviewJoinRequest(conferenceId, requestId, status) {
    try {
        const data = await apiFetch(`conferences/${conferenceId}/join-requests?request_id=${requestId}`, {
            method: 'PUT',
            body: JSON.stringify({ status: status })
        });
        
        if (data.success) {
            await this.getConferenceJoinRequests(conferenceId);
            return true;
        }
    } catch (error) {
        console.error('Ошибка рассмотрения запроса:', error);
    }
    return false;
},

// Получение участников конференции
async getConferenceParticipants(conferenceId) {
    try {
        const data = await apiGet(`conferences/${conferenceId}/participants`);
        if (data.success) {
            this.conferenceParticipants = data.data;
            return data.data;
        }
    } catch (error) {
        console.error('Ошибка получения участников:', error);
    }
    return [];
},

// Отправка сообщения в чат конференции
async sendConferenceChatMessage(conferenceId, message, guestName = null) {
    try {
        const data = await apiPost(`conferences/${conferenceId}/chat`, {
            message: message,
            guest_name: guestName
        });
        
        if (data.success) {
            await this.loadConferenceChat(conferenceId);
            this.conferenceChatMessage = '';
            return true;
        }
    } catch (error) {
        console.error('Ошибка отправки сообщения:', error);
    }
    return false;
},

// Загрузка чата конференции
async loadConferenceChat(conferenceId) {
    try {
        const data = await apiGet(`conferences/${conferenceId}/chat`);
        if (data.success) {
            this.conferenceChat = data.data;
            return data.data;
        }
    } catch (error) {
        console.error('Ошибка загрузки чата:', error);
    }
    return [];
},

// Инициализация локального видеопотока
async initLocalStream(videoEnabled = true, audioEnabled = true) {
    try {
        const constraints = {
            audio: audioEnabled,
            video: videoEnabled ? {
                width: { ideal: 1280 },
                height: { ideal: 720 }
            } : false
        };
        
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        this.localStream = stream;
        this.conferenceSettings.videoEnabled = videoEnabled;
        this.conferenceSettings.audioEnabled = audioEnabled;
        
        return stream;
    } catch (error) {
        console.error('Ошибка получения доступа к медиа:', error);
        this.showToast('Нет доступа к камере/микрофону', 'error');
        return null;
    }
},

// Переключение видео
toggleVideo() {
    if (this.localStream) {
        const videoTrack = this.localStream.getVideoTracks()[0];
        if (videoTrack) {
            videoTrack.enabled = !videoTrack.enabled;
            this.conferenceSettings.videoEnabled = videoTrack.enabled;
        }
    }
},

// Переключение аудио
toggleAudio() {
    if (this.localStream) {
        const audioTrack = this.localStream.getAudioTracks()[0];
        if (audioTrack) {
            audioTrack.enabled = !audioTrack.enabled;
            this.conferenceSettings.audioEnabled = audioTrack.enabled;
        }
    }
},

// Остановка локального потока
stopLocalStream() {
    if (this.localStream) {
        this.localStream.getTracks().forEach(track => track.stop());
        this.localStream = null;
    }
},

// Копирование ссылки на конференцию
copyConferenceLink(roomId) {
    const url = `${window.location.origin}/conference-join.html?room=${roomId}`;
    navigator.clipboard.writeText(url).then(() => {
        this.showToast('Ссылка скопирована', 'success');
    }).catch(() => {
        this.showToast('Ошибка копирования', 'error');
    });
},

// Открытие конференции
async openConference(conferenceId) {
    try {
        const data = await apiGet(`conferences/${conferenceId}`);
        if (data.success) {
            this.activeConference = data.data;
            await this.getConferenceParticipants(conferenceId);
            await this.loadConferenceChat(conferenceId);
            
            // Инициализируем видеопоток
            await this.initLocalStream();
            
            this.isInConference = true;
        }
    } catch (error) {
        console.error('Ошибка открытия конференции:', error);
    }
},

// Выход из конференции
leaveConference() {
    this.stopLocalStream();
    this.isInConference = false;
    this.activeConference = null;
    this.conferenceParticipants = [];
    this.conferenceChat = [];
},
