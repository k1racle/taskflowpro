/**
 * WebRTC клиент для звонков и встреч
 */

class WebRTCClient {
  constructor(userId) {
    this.userId = userId;
    this.ws = null;
    this.localStream = null;
    this.remoteStreams = new Map();
    this.peerConnections = new Map();
    this.currentCall = null;
    this.currentMeeting = null;
    
    // STUN/TURN серверы
    this.iceServers = {
      iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' }
        // Можно добавить TURN серверы
      ]
    };
  }

  // Подключение к WebSocket серверу
  connect(wsUrl) {
    return new Promise((resolve, reject) => {
      this.ws = new WebSocket(wsUrl);
      
      this.ws.onopen = () => {
        console.log('✅ WebSocket подключен');
        // Регистрируемся
        this.ws.send(JSON.stringify({
          type: 'register',
          userId: this.userId
        }));
        resolve();
      };
      
      this.ws.onmessage = (event) => {
        const data = JSON.parse(event.data);
        this.handleMessage(data);
      };
      
      this.ws.onclose = () => {
        console.log('❌ WebSocket отключен');
      };
      
      this.ws.onerror = (error) => {
        console.error('❌ WebSocket ошибка:', error);
        reject(error);
      };
    };
  }

  // Обработка входящих сообщений
  async handleMessage(data) {
    console.log('📨 Сообщение:', data.type);
    
    switch (data.type) {
      case 'incoming-call':
        await this.handleIncomingCall(data);
        break;
        
      case 'offer':
        await this.handleOffer(data);
        break;
        
      case 'answer':
        await this.handleAnswer(data);
        break;
        
      case 'ice-candidate':
        await this.handleIceCandidate(data);
        break;
        
      case 'call-accepted':
        await this.handleCallAccepted(data);
        break;
        
      case 'call-declined':
        await this.handleCallDeclined(data);
        break;
        
      case 'call-ended':
        await this.handleCallEnded(data);
        break;
        
      case 'meeting-invite':
        await this.handleMeetingInvite(data);
        break;
        
      case 'user-joined-meeting':
        await this.handleUserJoinedMeeting(data);
        break;
        
      case 'meeting-participants':
        await this.handleMeetingParticipants(data);
        break;
    }
  }

  // Исходящий звонок
  async call(recipientId, callType = 'audio', roomId = null) {
    const callId = this.generateId();
    
    this.ws.send(JSON.stringify({
      type: 'call',
      to: recipientId,
      callType: callType,
      roomId: roomId,
      callId: callId
    }));
    
    this.currentCall = {
      callId: callId,
      peerId: recipientId,
      type: callType,
      status: 'calling'
    };
    
    // Создаем PeerConnection
    await this.createPeerConnection(recipientId, callId, 'offer');
    
    // Получаем медиа
    await this.getLocalMedia(callType);
    
    return callId;
  }

  // Принятие звонка
  async acceptCall(callId, callerId) {
    this.currentCall = {
      callId: callId,
      peerId: callerId,
      status: 'accepted'
    };
    
    // Создаем PeerConnection для answer
    await this.createPeerConnection(callerId, callId, 'answer');
    
    // Получаем медиа
    await this.getLocalMedia(this.currentCall.type || 'audio');
    
    // Отправляем acceptance
    this.ws.send(JSON.stringify({
      type: 'accept-call',
      callId: callId,
      callerId: callerId
    }));
  }

  // Отклонение звонка
  declineCall(callId, callerId) {
    this.ws.send(JSON.stringify({
      type: 'decline-call',
      callId: callId,
      callerId: callerId
    }));
    this.currentCall = null;
  }

  // Завершение звонка
  endCall() {
    if (this.currentCall) {
      this.ws.send(JSON.stringify({
        type: 'end-call',
        callId: this.currentCall.callId
      }));
      
      this.closePeerConnections();
      this.stopLocalMedia();
      this.currentCall = null;
    }
  }

  // Создание PeerConnection
  async createPeerConnection(peerId, callId, type) {
    const pc = new RTCPeerConnection(this.iceServers);
    
    pc.onicecandidate = (event) => {
      if (event.candidate) {
        this.ws.send(JSON.stringify({
          type: 'ice-candidate',
          to: peerId,
          candidate: event.candidate,
          callId: callId
        }));
      }
    };
    
    pc.ontrack = (event) => {
      console.log('🎥 Получен трек:', event.streams[0]);
      this.remoteStreams.set(peerId, event.streams[0]);
      if (this.onRemoteStream) {
        this.onRemoteStream(peerId, event.streams[0]);
      }
    };
    
    // Добавляем локальные треки
    if (this.localStream) {
      this.localStream.getTracks().forEach(track => {
        pc.addTrack(track, this.localStream);
      });
    }
    
    this.peerConnections.set(peerId, pc);
    
    // Для offer создаем и отправляем offer
    if (type === 'offer') {
      const offer = await pc.createOffer();
      await pc.setLocalDescription(offer);
      
      this.ws.send(JSON.stringify({
        type: 'offer',
        to: peerId,
        offer: offer,
        callId: callId
      }));
    }
    
    return pc;
  }

  // Обработка offer
  async handleOffer(data) {
    const { from, offer, callId } = data;
    
    const pc = await this.createPeerConnection(from, callId, 'answer');
    await pc.setRemoteDescription(new RTCSessionDescription(offer));
    
    const answer = await pc.createAnswer();
    await pc.setLocalDescription(answer);
    
    this.ws.send(JSON.stringify({
      type: 'answer',
      to: from,
      answer: answer,
      callId: callId
    }));
  }

  // Обработка answer
  async handleAnswer(data) {
    const { from, answer, callId } = data;
    const pc = this.peerConnections.get(from);
    
    if (pc) {
      await pc.setRemoteDescription(new RTCSessionDescription(answer));
    }
  }

  // Обработка ICE кандидата
  async handleIceCandidate(data) {
    const { from, candidate, callId } = data;
    const pc = this.peerConnections.get(from);
    
    if (pc && candidate) {
      await pc.addIceCandidate(new RTCIceCandidate(candidate));
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
      
      // Добавляем треки в существующие соединения
      this.peerConnections.forEach(pc => {
        this.localStream.getTracks().forEach(track => {
          pc.addTrack(track, this.localStream);
        });
      });
      
    } catch (error) {
      console.error('❌ Ошибка получения медиа:', error);
      throw error;
    }
  }

  // Остановка локального медиа
  stopLocalMedia() {
    if (this.localStream) {
      this.localStream.getTracks().forEach(track => track.stop());
      this.localStream = null;
    }
  }

  // Закрытие всех соединений
  closePeerConnections() {
    this.peerConnections.forEach(pc => {
      pc.close();
    });
    this.peerConnections.clear();
  }

  // Присоединение к встрече
  async joinMeeting(meetingId, callType = 'video') {
    this.currentMeeting = {
      meetingId: meetingId,
      type: callType
    };
    
    this.ws.send(JSON.stringify({
      type: 'join-meeting',
      meetingId: meetingId
    }));
    
    await this.getLocalMedia(callType);
  }

  // Создание встречи
  async createMeeting(meetingType = 'video') {
    const meetingId = this.generateId();
    
    this.ws.send(JSON.stringify({
      type: 'create-meeting',
      meetingId: meetingId,
      meetingType: meetingType
    }));
    
    return meetingId;
  }

  // Приглашение на встречу
  inviteToMeeting(meetingId, participants) {
    this.ws.send(JSON.stringify({
      type: 'meeting-invite',
      meetingId: meetingId,
      participants: participants
    }));
  }

  // Выход из встречи
  leaveMeeting() {
    if (this.currentMeeting) {
      this.ws.send(JSON.stringify({
        type: 'leave-room',
        roomId: `meeting_${this.currentMeeting.meetingId}`
      }));
      
      this.closePeerConnections();
      this.stopLocalMedia();
      this.currentMeeting = null;
    }
  }

  // Обработчики событий
  handleIncomingCall(data) {
    if (this.onIncomingCall) {
      this.onIncomingCall(data);
    }
  }

  handleCallAccepted(data) {
    if (this.onCallAccepted) {
      this.onCallAccepted(data);
    }
  }

  handleCallDeclined(data) {
    if (this.onCallDeclined) {
      this.onCallDeclined(data);
    }
  }

  handleCallEnded(data) {
    this.closePeerConnections();
    this.stopLocalMedia();
    this.currentCall = null;
    
    if (this.onCallEnded) {
      this.onCallEnded(data);
    }
  }

  handleMeetingInvite(data) {
    if (this.onMeetingInvite) {
      this.onMeetingInvite(data);
    }
  }

  handleUserJoinedMeeting(data) {
    if (this.onUserJoinedMeeting) {
      this.onUserJoinedMeeting(data);
    }
  }

  handleMeetingParticipants(data) {
    if (this.onMeetingParticipants) {
      this.onMeetingParticipants(data);
    }
  }

  // Генерация ID
  generateId() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
      const r = Math.random() * 16 | 0;
      const v = c === 'x' ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
  }
}

// Экспорт для использования
if (typeof window !== 'undefined') {
  window.WebRTCClient = WebRTCClient;
}
