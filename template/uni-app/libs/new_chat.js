// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2024 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

import { getWorkermanUrl } from '@/api/kefu.js';

const Socket = function() {
  this.ws = null;
  this.connected = false;
  this.opening = false;
  this.identity = null;
  this.pendingAuthentication = null;
};

Socket.prototype = {
  resetAuthentication: function() {
    this.identity = null;
    this.authenticatedCredential = '';
    if (this.pendingAuthentication) {
      clearTimeout(this.pendingAuthentication.timer);
      this.pendingAuthentication.reject(new Error('聊天室身份确认未完成'));
      this.pendingAuthentication = null;
    }
  },
  init: function() {
    clearInterval(this.timer);
    this.timer = setInterval(() => { this.send({ type: 'ping' }).catch(() => {}); }, 10000);
  },
  send: function(data) {
    return new Promise((resolve, reject) => {
      if (!this.ws || !this.connected) return reject(new Error('聊天室连接未完成'));
      const socket = this.ws;
      socket.send({
        data: JSON.stringify(data),
        success: (result) => {
          if (this.ws !== socket || !this.connected) return reject(new Error('聊天室连接已变更'));
          resolve(result);
        },
        fail: () => reject(new Error('聊天室消息尚未发送')),
      });
    });
  },
  isAuthenticated: function(kind) {
    return this.connected && this.identity !== null && (!kind || this.identity.kind === kind);
  },
  authenticate: async function(type, credential, formType) {
    if (type !== 'login' || typeof credential !== 'string' || !credential || !this.ready) {
      throw new Error('聊天室身份未就绪');
    }
    await this.ready;
    if (this.isAuthenticated('api') && this.authenticatedCredential === credential) return this.identity;
    if (this.pendingAuthentication) {
      if (this.pendingAuthentication.credential === credential) return this.pendingAuthentication.promise;
      throw new Error('聊天室身份确认尚未完成');
    }
    this.identity = null;
    let resolve;
    let reject;
    const promise = new Promise((accept, fail) => { resolve = accept; reject = fail; });
    const pending = { credential, socket: this.ws, promise, resolve, reject };
    pending.timer = setTimeout(() => {
      if (this.pendingAuthentication === pending) this.resetAuthentication();
    }, 10000);
    this.pendingAuthentication = pending;
    this.send({ type, data: credential, form_type: formType }).catch(() => {
      if (this.pendingAuthentication === pending) this.resetAuthentication();
    });
    return promise;
  },
  onMessage: function(res) {
    let message;
    try { message = JSON.parse(res.data); } catch (error) { return; }
    const { type, data = {} } = message;
    const pending = this.pendingAuthentication;
    if (type === 'success' && pending && pending.socket === this.ws && data.kind === 'api'
      && Number.isInteger(data.uid) && data.uid > 0) {
      clearTimeout(pending.timer);
      this.pendingAuthentication = null;
      this.identity = Object.freeze({ kind: data.kind, uid: data.uid });
      this.authenticatedCredential = pending.credential;
      pending.resolve(this.identity);
    }
    if (message.close) this.resetAuthentication();
    uni.$emit(type, data);
  },
  onClose: function() {
    clearInterval(this.timer);
    this.resetAuthentication();
    this.connected = false;
    this.opening = false;
    this.ws = null;
    uni.closeSocket();
    uni.$emit('socket_close');
  },
  close: function() { this.onClose(); },
  onStart: function() {
    if (this.opening || this.connected) return this.ready;
    this.opening = true;
    this.resetAuthentication();
    this.ready = getWorkermanUrl().then((res) => new Promise((resolve, reject) => {
      const socket = uni.connectSocket({
        url: res.data.chat,
        header: { 'content-type': 'application/json' },
        method: 'GET',
        // Callback form preserves the SocketTask; readiness still waits for onOpen.
        complete: () => {},
      });
      this.ws = socket;
      socket.onOpen((event) => {
        if (this.ws !== socket) return;
        this.opening = false;
        this.connected = true;
        resolve(this);
        uni.$emit('socketOpen', event);
      });
      socket.onError(() => {
        if (this.ws !== socket) return;
        this.opening = false;
        reject(new Error('聊天室连接未完成'));
        uni.$emit('socket_error');
      });
      socket.onMessage((event) => {
        if (this.ws === socket) this.onMessage(event);
      });
      socket.onClose(() => {
        if (this.ws !== socket) return;
        clearInterval(this.timer);
        this.resetAuthentication();
        this.connected = false;
        this.opening = false;
        this.ws = null;
        reject(new Error('聊天室连接已关闭'));
        uni.$emit('socket_close');
      });
    }));
    this.ready.catch(() => { this.opening = false; });
    return this.ready;
  },
};

Socket.prototype.constructor = Socket;
export default Socket;
