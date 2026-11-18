// pages/comment/user.ts
// 我的评论列表页：与 user.wxml 配对
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    comment_list: [],
    page: 1,
    nomore: false,
  } as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      cartStore.refresh()
      that.setData({ page: 1, nomore: false })
      that.loadData(false, 1)
    })
  },

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('comment') })
    wx.setStorageSync('shareTitle', pageTitle('comment_list'))

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
    // 数据由 onShow 鉴权后加载
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData(concat = false, page: number | string = '') {
    const that = this

    http
      .get(route('comment.user'), {
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let dataBox
        if (concat) {
          dataBox = that.data.comment_list
          const dataNew = data.comment_list
          if (dataNew && dataNew.length) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = data.comment_list
        }

        // 后端返回真实页码/总页数时按页数收敛，避免空页后再多发一次请求
        const patch: Record<string, any> = { comment_list: dataBox, loadpage: false }
        if (data.page_count && data.page && data.page >= data.page_count) {
          patch.nomore = true
        }

        that.setData(patch)
      })
      .catch(function (err) {
        that.setData({ loadpage: false })
        douMsg(err.message || 'request_failed')
      })
  },

  onReachBottom() {
    const that = this
    if (!that.data.nomore) {
      const page = that.data.page + 1
      that.setData({ page, loadpage: true })
      setTimeout(function () {
        that.loadData(true)
      }, 1000)
    }
  },

  previewImage(e: WechatMiniprogram.TouchEvent) {
    const image = e.currentTarget.dataset.image
    wx.previewImage({ showmenu: true, current: image, urls: [image] })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    const url = e.currentTarget.dataset.url
    if (!url) {
      return
    }
    wx.navigateTo({ url })
  },
})
