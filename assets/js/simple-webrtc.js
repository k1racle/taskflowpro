/**
 * WebRTC клиент для звонков БЕЗ WebSocket сервера
 * Работает через HTTP API (Long Polling)
 */

class SimpleWebRTC {
  constructor(userId) {
    this.userId = userId;
    this.localStream = null;
    this.remoteStream = null;
    this.peerConnection = null;
    this.currentCall = null;
    this.pollingInterval = null;
    
    // STUN серверы Google (бесплатные)
    this.iceServers = {
      iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' }
      ]
    };
    
    // Интервал опроса (сек)
    this.pollingIntervalSec = 3;
  }

  // Инициализация
  async init() {
    console.log('🚀 SimpleWebRTC инициализирован');
    this.startPolling();
  }

  // Long Polling - проверка входящих звонков
  async startPolling() {
    if (this.pollingInterval) return;
    
    console.log(`🔄 Запуск опроса звонков (каждые ${this.pollingIntervalSec} сек)`);
    
    this.pollingInterval = setInterval(async () => {
      await this.checkIncomingCalls();
    }, this.pollingIntervalSec * 1000);
  }

  stopPolling() {
    if (this.pollingInterval) {
      clearInterval(this.pollingInterval);
      this.pollingInterval = null;
    }
  }

  // Проверка входящих звонков
  async checkIncomingCalls() {
    if (this.currentCall && this.currentCall.status === 'active') return;
    
    try {
      const response = await fetch(`api/chat/calls?_t=${Date.now()}`);
      const data = await response.json();
      
      if (data.success && data.data) {
        console.log('📞 Входящий звонок:', data.data);
        this.handleIncomingCall(data.data);
      }
    } catch (error) {
      // Игнорируем ошибки (таблицы может не быть)
    }
  }

  // Исходящий звонок
  async call(recipientId, callType = 'audio', roomId = null) {
    console.log(`📞 Звонок пользователю ${recipientId} (${callType})`);

    try {
      // Создаём звонок в БД
      const response = await fetch('api/chat/calls', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${getToken()}`
        },
        body: JSON.stringify({
          recipient_id: recipientId,
          call_type: callType,
          room_id: roomId
        })
      });

      const result = await response.json();

      if (result.success) {
        this.currentCall = {
          callId: result.data.call_id,
          peerId: recipientId,
          type: callType,
          status: 'calling',
          initiator: true
        };

        // Создаем PeerConnection
        await this.createPeerConnection('offer');

        // Получаем медиа
        await this.getLocalMedia(callType);

        return result.data.call_id;
      } else {
        throw new Error(result.error || 'Ошибка создания звонка');
      }
    } catch (error) {
      console.error('❌ Ошибка звонка:', error);
      throw error;
    }
  }

  // Принятие звонка
  async acceptCall(callId, callerId, callType) {
    console.log(`✅ Принятие звонка ${callId} от ${callerId}`);

    this.currentCall = {
      callId: callId,
      peerId: callerId,
      type: callType,
      status: 'accepted',
      initiator: false
    };

    // Отправляем принятие
    await fetch(`api/chat/calls/${callId}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${getToken()}`
      },
      body: JSON.stringify({ status: 'accepted' })
    });

    // Создаем PeerConnection
    await this.createPeerConnection('answer');

    // Получаем медиа
    await this.getLocalMedia(callType);
  }

  // Отклонение звонка
  async declineCall(callId, callerId) {
    console.log(`❌ Отклонение звонка ${callId}`);

    await fetch(`api/chat/calls/${callId}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${getToken()}`
      },
      body: JSON.stringify({ status: 'declined' })
    });

    this.currentCall = null;
  }

  // Завершение звонка
  async endCall() {
    console.log('🔚 Завершение звонка');

    if (this.currentCall) {
      await fetch(`api/chat/calls/${this.currentCall.callId}`, {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${getToken()}`
        }
      });
      
      this.closeConnection();
      this.currentCall = null;
    }
  }

  // Создание PeerConnection
  async createPeerConnection(type) {
    console.log('🔧 Создание PeerConnection:', type);
    
    this.peerConnection = new RTCPeerConnection(this.iceServers);
    
    // ICE кандидат
    this.peerConnection.onicecandidate = (event) => {
      if (event.candidate) {
        console.log('❄️ ICE кандидат');
        this.sendIceCandidate(event.candidate);
      }
    };
    
    // Получение удалённого потока
    this.peerConnection.ontrack = (event) => {
      console.log('🎥 Получен трек');
      this.remoteStream = event.streams[0];
      if (this.onRemoteStream) {
        this.onRemoteStream(event.streams[0]);
      }
    };
    
    // Добавляем локальные треки
    if (this.localStream) {
      this.localStream.getTracks().forEach(track => {
        this.peerConnection.addTrack(track, this.localStream);
      });
    }
    
    // Для offer создаём и отправляем offer
    if (type === 'offer') {
      const offer = await this.peerConnection.createOffer();
      await this.peerConnection.setLocalDescription(offer);
      
      // Сохраняем offer в БД
      await this.saveSessionData('offer', offer);
    }
    
    // Запускаем процесс обмена SDP
    this.startSDPExchange();
  }

  // Обмен SDP через БД (Long Polling)
  async startSDPExchange() {
    const checkInterval = setInterval(async () => {
      if (!this.currentCall) {
        clearInterval(checkInterval);
        return;
      }
      
      try {
        const response = await fetch(`api/chat/webrtc/${this.currentCall.callId}?_t=${Date.now()}`);
        const data = await response.json();
        
        if (data.success && data.data) {
          const { type, sdp, candidate } = data.data;
          
          if (type === 'offer' && !this.peerConnection.remoteDescription) {
            await this.peerConnection.setRemoteDescription(new RTCSessionDescription(sdp));
            
            // Создаём answer
            const answer = await this.peerConnection.createAnswer();
            await this.peerConnection.setLocalDescription(answer);
            await this.saveSessionData('answer', answer);
          }
          
          if (type === 'answer' && !this.peerConnection.remoteDescription) {
            await this.peerConnection.setRemoteDescription(new RTCSessionDescription(sdp));
          }
          
          if (type === 'ice-candidate' && candidate) {
            await this.peerConnection.addIceCandidate(new RTCIceCandidate(candidate));
          }
        }
      } catch (error) {
        console.warn('Ошибка обмена SDP:', error);
      }
    }, 2000);
  }

  // Отправка ICE кандидата
  async sendIceCandidate(candidate) {
    try {
      await fetch('api/chat/webrtc/ice', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${getToken()}`
        },
        body: JSON.stringify({
          call_id: this.currentCall.callId,
          candidate: candidate,
          to_user_id: this.currentCall.peerId
        })
      });
    } catch (error) {
      console.warn('Ошибка отправки ICE:', error);
    }
  }

  // Сохранение SDP данных
  async saveSessionData(type, data) {
    try {
      await fetch('api/chat/webrtc/session', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${getToken()}`
        },
        body: JSON.stringify({
          call_id: this.currentCall.callId,
          session_type: type,
          sdp_data: JSON.stringify(data),
          to_user_id: this.currentCall.peerId
        })
      });
    } catch (error) {
      console.warn('Ошибка сохранения сессии:', error);
    }
  }

  // Получение локального медиа
  async getLocalMedia(callType) {
    try {
      const constraints = {
        audio: true,
        video: callType === 'video'
      };
      
      this.localStream = await navigator.mediaDevices.getUserMedia(constraints);
      
      if (this.onLocalStream) {
        this.onLocalStream(this.localStream);
      }
      
    } catch (error) {
      console.error('❌ Ошибка получения медиа:', error);
      throw error;
    }
  }

  // Закрытие соединения
  closeConnection() {
    if (this.peerConnection) {
      this.peerConnection.close();
      this.peerConnection = null;
    }
    
    if (this.localStream) {
      this.localStream.getTracks().forEach(track => track.stop());
      this.localStream = null;
    }
    
    this.stopPolling();
  }

  // Обработка входящего звонка
  handleIncomingCall(data) {
    console.log('📞 Обработка входящего звонка');
    
    if (this.onIncomingCall) {
      this.onIncomingCall({
        call_id: data.call_id,
        caller_id: data.caller_id || data.call_id,
        caller_name: data.caller_name,
        call_type: data.call_type
      });
    }
  }
}

// Экспорт
if (typeof window !== 'undefined') {
  window.SimpleWebRTC = SimpleWebRTC;
}
