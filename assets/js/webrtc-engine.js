/**
 * WebRTC Engine - Движок для видеоконференций TaskFlow
 * 
 * Обрабатывает:
 * - Создание и получение SDP оферов/ответов
 * - Обмен ICE кандидатами
 * - Управление RTCPeerConnection
 * - Демонстрацию экрана
 */

// ============================================
// КОНФИГУРАЦИЯ
// ============================================
const WEBRTC_CONFIG = {
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
        { urls: 'stun:stun2.l.google.com:19302' },
        { urls: 'stun:stun3.l.google.com:19302' },
        { urls: 'stun:stun4.l.google.com:19302' }
    ],
    iceCandidatePollInterval: 1000, // мс
    offerPollInterval: 2000, // мс
    maxRetries: 3,
    retryDelay: 1000
};

// ============================================
// КЛАСС WebRTCEngine
// ============================================
class WebRTCEngine {
    constructor(options = {}) {
        this.conferenceId = options.conferenceId;
        this.participantId = options.participantId;
        this.userId = options.userId;
        this.localStream = options.localStream;
        
        this.peerConnections = new Map(); // peerId -> RTCPeerConnection
        this.remoteStreams = new Map(); // peerId -> MediaStream
        this.dataChannels = new Map(); // peerId -> RTCDataChannel
        
        this.isScreenSharing = false;
        this.screenStream = null;
        this.pollIntervals = [];
        
        this.onTrack = options.onTrack || (() => {});
        this.onPeerJoin = options.onPeerJoin || (() => {});
        this.onPeerLeave = options.onPeerLeave || (() => {});
        this.onError = options.onError || (() => {});
    }

    // ============================================
    // ИНИЦИАЛИЗАЦИЯ
    // ============================================
    
    /**
     * Создать RTCPeerConnection
     */
    createPeerConnection(peerId) {
        const pc = new RTCPeerConnection(WEBRTC_CONFIG);

        // Добавляем локальный поток
        if (this.localStream) {
            this.localStream.getTracks().forEach(track => {
                try {
                    pc.addTrack(track, this.localStream);
                } catch (e) {
                    console.warn('Не удалось добавить трек:', e);
                }
            });
        }

        // Обработка удалённых треков
        pc.ontrack = (event) => {
            console.log('Получен удалённый трек от:', peerId);
            const stream = event.streams[0];
            if (stream) {
                this.remoteStreams.set(peerId, stream);
                this.onTrack(peerId, stream);
            }
        };

        // Обработка ICE кандидатов
        pc.onicecandidate = (event) => {
            if (event.candidate) {
                this.sendIceCandidate(event.candidate, peerId);
            }
        };

        // Обработка состояния соединения
        pc.oniceconnectionstatechange = () => {
            console.log(`ICE state для ${peerId}:`, pc.iceConnectionState);
            
            if (pc.iceConnectionState === 'failed' || pc.iceConnectionState === 'disconnected') {
                this.handleConnectionFailure(peerId);
            }
            
            if (pc.iceConnectionState === 'connected') {
                this.onPeerJoin(peerId);
            }
        };

        // Обработка data channel (для чата и сигналов)
        pc.ondatachannel = (event) => {
            this.handleDataChannel(event.channel, peerId);
        };

        this.peerConnections.set(peerId, pc);
        return pc;
    }

    /**
     * Создать офер и отправить хосту
     */
    async createOfferToHost() {
        try {
            const pc = this.createPeerConnection('host');
            
            // Создаём data channel для сигналов
            const dc = pc.createDataChannel('signals');
            this.setupDataChannel(dc, 'host');
            
            // Создаём SDP офер
            const offer = await pc.createOffer({
                offerToReceiveAudio: true,
                offerToReceiveVideo: true
            });
            await pc.setLocalDescription(offer);
            
            // Ждём немного для сбора ICE кандидатов
            await this.waitForIceGathering(pc);
            
            // Отправляем офер через API
            await this.sendOffer(pc.localDescription);
            
            console.log('Офер отправлен хосту');
            return true;
        } catch (error) {
            console.error('Ошибка создания офера:', error);
            this.onError('Не удалось создать подключение', error);
            return false;
        }
    }

    /**
     * Обработать входящий офер
     */
    async handleIncomingOffer(offerData) {
        try {
            const peerId = offerData.from_participant_id;
            const pc = this.createPeerConnection(peerId);
            
            // Устанавливаем remote description
            await pc.setRemoteDescription(new RTCSessionDescription(JSON.parse(offerData.sdp_offer)));
            
            // Создаём ответ
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            
            // Ждём ICE кандидаты
            await this.waitForIceGathering(pc);
            
            // Отправляем ответ через API
            await this.sendAnswer(offerData.id, pc.localDescription);
            
            console.log('Отправлен ответ на офер от:', peerId);
            return true;
        } catch (error) {
            console.error('Ошибка обработки офера:', error);
            this.onError('Не удалось обработать офер', error);
            return false;
        }
    }

    /**
     * Обработать входящий ответ
     */
    async handleIncomingAnswer(answerData) {
        try {
            const peerId = answerData.from_participant_id;
            const pc = this.peerConnections.get(peerId);
            
            if (!pc) {
                console.warn('PeerConnection не найден для:', peerId);
                return false;
            }
            
            await pc.setRemoteDescription(new RTCSessionDescription(JSON.parse(answerData.sdp_answer)));
            console.log('Установлен remote description от:', peerId);
            return true;
        } catch (error) {
            console.error('Ошибка обработки ответа:', error);
            return false;
        }
    }

    /**
     * Добавить ICE кандидат
     */
    async addIceCandidate(iceData) {
        try {
            const peerId = iceData.from_participant_id;
            const pc = this.peerConnections.get(peerId);
            
            if (!pc) {
                return false;
            }
            
            if (iceData.candidate) {
                const candidate = new RTCIceCandidate(JSON.parse(iceData.candidate));
                await pc.addIceCandidate(candidate);
            }
            return true;
        } catch (error) {
            console.error('Ошибка добавления ICE кандидата:', error);
            return false;
        }
    }

    // ============================================
    // ОТПРАВКА СИГНАЛОВ
    // ============================================
    
    /**
     * Отправить офер через API
     */
    async sendOffer(sdp) {
        try {
            const response = await fetch('api/webrtc/offer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    conference_id: this.conferenceId,
                    from_participant_id: this.participantId,
                    to_participant_id: 1, // host
                    sdp: JSON.stringify(sdp)
                })
            });
            
            const data = await response.json();
            return data.success;
        } catch (error) {
            console.error('Ошибка отправки офера:', error);
            return false;
        }
    }

    /**
     * Отправить ответ через API
     */
    async sendAnswer(offerId, sdp) {
        try {
            const response = await fetch(`api/webrtc/offer/${offerId}/answer`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    sdp: JSON.stringify(sdp),
                    from_participant_id: this.participantId
                })
            });
            
            const data = await response.json();
            return data.success;
        } catch (error) {
            console.error('Ошибка отправки ответа:', error);
            return false;
        }
    }

    /**
     * Отправить ICE кандидат через API
     */
    async sendIceCandidate(candidate, toPeer) {
        try {
            await fetch('api/webrtc/ice', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    conference_id: this.conferenceId,
                    from_participant_id: this.participantId,
                    to_participant_id: toPeer === 'host' ? 1 : null,
                    candidate: JSON.stringify(candidate),
                    sdp_mid: candidate.sdpMid,
                    sdp_mline_index: candidate.sdpMLineIndex
                })
            });
        } catch (error) {
            console.error('Ошибка отправки ICE кандидата:', error);
        }
    }

    // ============================================
    // ОПРОС СИГНАЛОВ
    // ============================================
    
    /**
     * Запустить опрос сигналов
     */
    startSignalPolling() {
        // Опрос ответов на оферы
        const offerInterval = setInterval(() => this.pollForAnswers(), WEBRTC_CONFIG.offerPollInterval);
        this.pollIntervals.push(offerInterval);
        
        // Опрос ICE кандидатов
        const iceInterval = setInterval(() => this.pollForIceCandidates(), WEBRTC_CONFIG.iceCandidatePollInterval);
        this.pollIntervals.push(iceInterval);
    }

    /**
     * Опрос ответов на оферы
     */
    async pollForAnswers() {
        try {
            const response = await fetch(`api/webrtc/offers/${this.conferenceId}?participant_id=${this.participantId}`);
            const data = await response.json();
            
            if (data.success && data.data.length > 0) {
                for (const offer of data.data) {
                    await this.handleIncomingOffer(offer);
                }
            }
        } catch (error) {
            console.error('Ошибка опроса ответов:', error);
        }
    }

    /**
     * Опрос ICE кандидатов
     */
    async pollForIceCandidates() {
        try {
            const response = await fetch(`api/webrtc/ice/${this.conferenceId}?participant_id=${this.participantId}`);
            const data = await response.json();
            
            if (data.success && data.data.length > 0) {
                for (const ice of data.data) {
                    await this.addIceCandidate(ice);
                }
            }
        } catch (error) {
            console.error('Ошибка опроса ICE:', error);
        }
    }

    /**
     * Остановить опрос сигналов
     */
    stopSignalPolling() {
        this.pollIntervals.forEach(interval => clearInterval(interval));
        this.pollIntervals = [];
    }

    // ============================================
    // DEMONSTRATION SCREEN
    // ============================================
    
    /**
     * Начать демонстрацию экрана
     */
    async startScreenShare() {
        try {
            this.screenStream = await navigator.mediaDevices.getDisplayMedia({
                video: {
                    width: { ideal: 1920 },
                    height: { ideal: 1080 },
                    frameRate: { ideal: 30 }
                },
                audio: false
            });

            const screenTrack = this.screenStream.getVideoTracks()[0];
            
            // Заменяем видео трек во всех подключениях
            this.peerConnections.forEach((pc, peerId) => {
                const sender = pc.getSenders().find(s => s.track?.kind === 'video');
                if (sender) {
                    sender.replaceTrack(screenTrack);
                }
            });

            this.isScreenSharing = true;
            
            // Обработка остановки демонстрации
            screenTrack.onended = () => {
                this.stopScreenShare();
            };

            return true;
        } catch (error) {
            console.error('Ошибка демонстрации экрана:', error);
            this.onError('Не удалось начать демонстрацию экрана', error);
            return false;
        }
    }

    /**
     * Остановить демонстрацию экрана
     */
    async stopScreenShare() {
        if (this.screenStream) {
            this.screenStream.getTracks().forEach(track => track.stop());
            this.screenStream = null;
        }

        // Возвращаем камеру
        if (this.localStream) {
            const videoTrack = this.localStream.getVideoTracks()[0];
            
            this.peerConnections.forEach((pc, peerId) => {
                const sender = pc.getSenders().find(s => s.track?.kind === 'video');
                if (sender) {
                    sender.replaceTrack(videoTrack);
                }
            });
        }

        this.isScreenSharing = false;
    }

    // ============================================
    // DATA CHANNEL
    // ============================================
    
    /**
     * Настроить data channel
     */
    setupDataChannel(channel, peerId) {
        channel.onopen = () => {
            console.log('Data channel открыт для:', peerId);
            this.dataChannels.set(peerId, channel);
        };

        channel.onmessage = (event) => {
            this.handleDataMessage(event.data, peerId);
        };

        channel.onclose = () => {
            console.log('Data channel закрыт для:', peerId);
            this.dataChannels.delete(peerId);
        };
    }

    /**
     * Обработать data channel
     */
    handleDataChannel(channel, peerId) {
        this.setupDataChannel(channel, peerId);
    }

    /**
     * Обработать сообщение через data channel
     */
    handleDataMessage(data, peerId) {
        try {
            const message = JSON.parse(data);
            console.log('Получено сообщение от:', peerId, message);
            
            // Здесь можно обрабатывать сообщения чата, сигналы и т.д.
        } catch (error) {
            console.error('Ошибка обработки сообщения:', error);
        }
    }

    /**
     * Отправить сообщение через data channel
     */
    sendDataMessage(peerId, message) {
        const channel = this.dataChannels.get(peerId);
        if (channel && channel.readyState === 'open') {
            channel.send(JSON.stringify(message));
            return true;
        }
        return false;
    }

    /**
     * Отправить сообщение всем
     */
    broadcastDataMessage(message) {
        this.dataChannels.forEach((channel, peerId) => {
            this.sendDataMessage(peerId, message);
        });
    }

    // ============================================
    // УТИЛИТЫ
    // ============================================
    
    /**
     * Ждать завершения сбора ICE кандидатов
     */
    waitForIceGathering(pc) {
        return new Promise((resolve) => {
            if (pc.iceGatheringState === 'complete') {
                resolve();
                return;
            }

            const checkState = () => {
                if (pc.iceGatheringState === 'complete') {
                    pc.removeEventListener('icegatheringstatechange', checkState);
                    resolve();
                }
            };

            pc.addEventListener('icegatheringstatechange', checkState);
            
            // Таймаут на случай если состояние не изменится
            setTimeout(resolve, 1000);
        });
    }

    /**
     * Обработка сбоя подключения
     */
    async handleConnectionFailure(peerId) {
        console.log('Попытка переподключения к:', peerId);
        
        const pc = this.peerConnections.get(peerId);
        if (pc) {
            try {
                await pc.restartIce();
            } catch (error) {
                console.error('Ошибка restartIce:', error);
            }
        }
    }

    /**
     * Закрыть все подключения
     */
    close() {
        this.stopSignalPolling();
        
        this.peerConnections.forEach((pc, peerId) => {
            pc.close();
        });
        this.peerConnections.clear();
        
        this.remoteStreams.clear();
        this.dataChannels.clear();
        
        if (this.screenStream) {
            this.screenStream.getTracks().forEach(track => track.stop());
        }
    }

    /**
     * Получить удалённый стрим
     */
    getRemoteStream(peerId) {
        return this.remoteStreams.get(peerId);
    }

    /**
     * Получить все подключения
     */
    getPeerConnections() {
        return new Map(this.peerConnections);
    }
}

// ============================================
// ЭКСПОРТ
// ============================================
if (typeof window !== 'undefined') {
    window.WebRTCEngine = WebRTCEngine;
    window.WEBRTC_CONFIG = WEBRTC_CONFIG;
}
