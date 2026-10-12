// +---------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +---------------------------------------------------------------------
// | Copyright (c) 2016~2023 https://www.crmeb.com All rights reserved.
// +---------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +---------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +---------------------------------------------------------------------

import { setCookies } from '@/libs/util';
import { getWorkermanUrl } from '@/api/kefu';
import Vue from 'vue';

const vm = new Vue();
let wsAdminSocketUrl = '';
let wsKefuSocketUrl = '';
const authKinds = { login: 'api', kefu_login: 'kefu', guest_login: 'guest' };

class wsSocket {
  constructor(opt) {
    this.ws = null;
    this.opt = opt || {};
    this.key = opt.key;
    this.identity = null;
    this.pendingAuthentication = null;
    this.init(this.key);
  }

  resetAuthentication() {
    this.identity = null;
    this.authenticatedCredential = '';
    if (this.pendingAuthentication) {
      clearTimeout(this.pendingAuthentication.timer);
      this.pendingAuthentication.reject(new Error('聊天室身份确认未完成'));
      this.pendingAuthentication = null;
    }
  }

  init(key = this.key) {
    this.key = key;
    this.timer && clearInterval(this.timer);
    this.resetAuthentication();
    this.socketStatus = false;
    this.serverClose = '';
    const previous = this.ws;
    const socket = new WebSocket(key === 1 ? wsAdminSocketUrl : wsKefuSocketUrl);
    this.ws = socket;
    if (previous && previous.readyState < WebSocket.CLOSING) previous.close();
    this.ready = new Promise((resolve, reject) => {
      this.resolveReady = resolve;
      this.rejectReady = reject;
    });
    this.ready.catch(() => {});
    socket.onopen = () => {
      if (this.ws !== socket) return;
      this.socketStatus = true;
      this.resolveReady(this);
      this.ping();
      this.opt.open && this.opt.open();
    };
    socket.onmessage = (event) => {
      if (this.ws === socket) this.onMessage(event);
    };
    socket.onerror = (event) => {
      if (this.ws !== socket) return;
      this.rejectReady(new Error('聊天室连接未完成'));
      this.opt.error && this.opt.error(event);
    };
    socket.onclose = (event) => {
      if (this.ws !== socket) return;
      this.timer && clearInterval(this.timer);
      this.socketStatus = false;
      this.resetAuthentication();
      this.rejectReady(new Error('聊天室连接已关闭'));
      this.opt.close && this.opt.close(event);
    };
    return this.ready;
  }

  async whenOpen() {
    if (!this.ws || this.ws.readyState === WebSocket.CLOSED) this.init(this.key);
    if (this.ws.readyState === WebSocket.CLOSING) throw new Error('聊天室连接未完成');
    await this.ready;
    return this;
  }

  async authenticate(type, credential, formType = 3) {
    const kind = authKinds[type];
    if (!kind || typeof credential !== 'string' || !credential) throw new Error('聊天室身份未就绪');
    await this.whenOpen();
    if (this.isAuthenticated(kind) && this.authenticatedCredential === credential) return this.identity;
    if (this.pendingAuthentication) {
      if (this.pendingAuthentication.kind === kind && this.pendingAuthentication.credential === credential) {
        return this.pendingAuthentication.promise;
      }
      throw new Error('聊天室身份确认尚未完成');
    }
    this.identity = null;
    let resolve;
    let reject;
    const promise = new Promise((accept, fail) => { resolve = accept; reject = fail; });
    const pending = { kind, credential, socket: this.ws, promise, resolve, reject };
    pending.timer = setTimeout(() => {
      if (this.pendingAuthentication === pending) this.resetAuthentication();
    }, 10000);
    this.pendingAuthentication = pending;
    this.send({ type, data: credential, form_type: formType }).catch(() => {
      if (this.pendingAuthentication === pending) this.resetAuthentication();
    });
    return promise;
  }

  isAuthenticated(kind) {
    return this.socketStatus && this.ws && this.ws.readyState === WebSocket.OPEN
      && this.identity !== null && (!kind || this.identity.kind === kind)
      && (this.identity.kind !== 'guest' || this.identity.expires_at > Math.floor(Date.now() / 1000));
  }

  ping() {
    this.timer && clearInterval(this.timer);
    this.timer = setInterval(() => { this.send({ type: 'ping' }).catch(() => {}); }, 10000);
  }

  send(data) {
    return new Promise((resolve, reject) => {
      if (!this.ws || this.ws.readyState !== WebSocket.OPEN) return reject(new Error('聊天室连接未完成'));
      try {
        this.ws.send(JSON.stringify(data));
        resolve({ status: true });
      } catch (error) {
        reject(new Error('聊天室消息尚未发送'));
      }
    });
  }

  onMessage(event) {
    let message;
    try { message = JSON.parse(event.data); } catch (error) { return; }
    const { type, data = {} } = message;
    const pending = this.pendingAuthentication;
    if (type === 'success' && pending && pending.socket === this.ws && data.kind === pending.kind
      && Number.isInteger(data.uid) && (data.kind === 'guest' ? data.uid < 0 : data.uid > 0)) {
      if (data.expires_at !== undefined && !Number.isInteger(data.expires_at)) return;
      clearTimeout(pending.timer);
      this.pendingAuthentication = null;
      this.identity = Object.freeze({ kind: data.kind, uid: data.uid, expires_at: data.expires_at });
      this.authenticatedCredential = pending.credential;
      pending.resolve(this.identity);
    }
    if (message.close) {
      this.serverClose = type;
      this.resetAuthentication();
    }
    this.opt.message && this.opt.message(event);
  }

  close() {
    this.timer && clearInterval(this.timer);
    this.resetAuthentication();
    this.ws && this.ws.close();
  }

  $on(...args) { vm.$on(...args); }
  $off(...args) { vm.$off(...args); }
}

const addresses = getWorkermanUrl().then((res) => {
  wsAdminSocketUrl = res.data.admin;
  wsKefuSocketUrl = res.data.chat;
  setCookies('WS_ADMIN_URL', wsAdminSocketUrl);
  setCookies('WS_CHAT_URL', wsKefuSocketUrl);
});

function createSocket(key) {
  return addresses.then(() => new Promise((resolve, reject) => {
    const ws = new wsSocket({
      key,
      open() { resolve(ws); vm.$emit('socket_open', key); },
      error() { reject(new Error('聊天室连接未完成')); },
      message(event) {
        const { type, data = {} } = JSON.parse(event.data);
        vm.$emit(type, data);
      },
      close(event) { vm.$emit('close', { e: event, key, serverClose: ws.serverClose }); },
    });
    ws.ready.catch(reject);
  }));
}

let adminSocket;
export function getAdminSocket() {
  if (!adminSocket) adminSocket = createSocket(1);
  return adminSocket;
}
export const Socket = createSocket(2);
