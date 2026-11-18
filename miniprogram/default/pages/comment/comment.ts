// pages/comment/comment.ts
// 发表评论表单页：与 comment.wxml 配对（数据来自 comment.user.add，提交走 comment.user.store）
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    avatarTemp: '',
    checkValue: 1,
    textAreaMaxLen: 300,
    inputValueLength: 0,
    anonymous: 0,
    content: '',
    stars: [0, 1, 2, 3, 4],
    normalSrc: '../../images/star.png',
    selectedSrc: '../../images/star_on.png',
    star_unit: 5,
  } as Record<string, any>,

  onShow() {
    authStore.ensureLogin()
  },

  onLoad(options) {
    const that = this

    that.setData({ title: pageTitle('comment') })

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .get(route('comment.user.add'), {
          order_item_id: options.order_item_id,
        })
        .then(function (data) {
          that.setData({
            item: data.item,
            order_item_id: data.order_item_id,
            html_file_list: data.html_file_list,
          })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  commentPost(e: WechatMiniprogram.CustomEvent) {
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('comment.user.store'), {
          order_item_id: e.detail.value.order_item_id,
          content: e.detail.value.content,
          star: e.detail.value.star,
          anonymous: e.detail.value.anonymous,
        })
        .then(function (data) {
          douMsg(data.success || 'ok', '/pages/comment/user')
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  filebox(e: WechatMiniprogram.TouchEvent) {
    const that = this

    wx.chooseImage({
      count: 1,
      sizeType: ['original', 'compressed'],
      sourceType: ['album', 'camera'],
      success(info) {
        const tempFilePaths = info.tempFilePaths
        wx.uploadFile({
          url: route('comment.user.filebox'),
          filePath: tempFilePaths[0],
          formData: {
            item_id: e.currentTarget.dataset.item_id,
          },
          header: {
            Authorization: 'Bearer ' + (wx.getStorageSync('api_token') || ''),
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          name: 'gallery',
          success(res) {
            let raw: any = {}
            try {
              raw = res && res.data ? JSON.parse(res.data) : {}
            } catch (err) {
              raw = {}
            }
            if (!raw || raw.code !== 'OK') {
              douMsg((raw && raw.message) || 'request_failed')
              return
            }
            const payload = raw.data || {}
            that.setData({ html_file_list: payload.html_file_list })
          },
        })
      },
    })
  },

  fileDel(e: WechatMiniprogram.TouchEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('comment.user.filedel'), {
          number: e.currentTarget.dataset.number,
          item_id: e.currentTarget.dataset.item_id,
        })
        .then(function (data) {
          that.setData({ html_file_list: data.html_file_list })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  bindKeyInput(e: WechatMiniprogram.CustomEvent) {
    const maxLen = this.data.textAreaMaxLen
    if (e.detail.value.length > maxLen) {
      this.setData({ inputValueLength: maxLen, inputValue: e.detail.value })
    } else {
      this.setData({ inputValueLength: e.detail.value.length, inputValue: e.detail.value })
    }
  },

  selectServer(e: WechatMiniprogram.TouchEvent) {
    // 星级最低 1 分（后端也会把越界值收敛为默认 5 分）
    this.setData({ star_unit: e.currentTarget.dataset.star_unit })
  },

  anonymous(e: WechatMiniprogram.CustomEvent) {
    if (e.detail.value.includes('1')) {
      this.setData({ anonymous: 1 })
    } else {
      this.setData({ anonymous: 0 })
    }
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    const url = e.currentTarget.dataset.url
    if (!url) {
      return
    }
    wx.navigateTo({ url })
  },
})
