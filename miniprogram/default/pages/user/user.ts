// pages/user/user.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

const app = getApp<IAppOption>()

// navbar 是 fixed 的，沉浸式头部要按「状态栏 + 导航栏」高度把背景图顶到屏幕最上沿
const NAV_HEIGHT = Number(app.globalData.navigationBarAndStatusBarHeight) || 0

// 导航栏由透明渐变到实底的滚动区间（px）
const NAV_FADE_START = 20
const NAV_FADE_END = 80

Page({
  data: {
    dou: {
      user: {},
      auth: {
        is_login: false,
        is_vip: false,
        is_work: false,
        is_distribution: false,
      },
      vip: {},
      work: {},
      distribution: {},
    },
    welcome: '',
    link_user_center: {},
    if_connect_plugin: false,
    // 沉浸式头部：仅当后台配置了 data.user_head_bg.image 时由 wxml 取用
    navHeight: NAV_HEIGHT,
    navTopPadding: NAV_HEIGHT + 8,
    // 滚动前导航栏背景透明；无图时 wxml 恒取 1，不受此值影响
    navOpacity: 0,
  } as Record<string, any>,

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('user') })

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
  },

  // 有头部背景图时，导航栏随滚动由透明渐变为主色实底；无图时 wxml 恒取 1
  onPageScroll(e: WechatMiniprogram.Page.IPageScrollOption) {
    const scrollTop = e.scrollTop > 0 ? e.scrollTop : 0
    let opacity = 0
    if (scrollTop >= NAV_FADE_END) {
      opacity = 1
    } else if (scrollTop > NAV_FADE_START) {
      opacity = (scrollTop - NAV_FADE_START) / (NAV_FADE_END - NAV_FADE_START)
    }
    opacity = Math.round(opacity * 100) / 100
    if (opacity !== this.data.navOpacity) {
      this.setData({ navOpacity: opacity })
    }
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  onShow() {
    const that = this

    http
      .get(route('user'), {})
      .then(function (data) {
        that.setData({
          dou: data.dou || {
            user: {},
            auth: {
              is_login: false,
              is_vip: false,
              is_work: false,
              is_distribution: false,
            },
            vip: {},
            work: {},
            distribution: {},
          },
          welcome: data.welcome || '',
          link_user_center: data.link_user_center || {},
          if_connect_plugin: data.if_connect_plugin || false,
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })

    // tabBar 购物车角标由 cartStore.badge 统一驱动
    cartStore.refresh()
  },

  userLogout() {
    wx.clearStorageSync() // 清空缓存，退出登录
    authStore.logout()
    cartStore.reset()
    wx.reLaunch({ url: '/pages/index/index' })
  },

  // 下载图片
  downloadImage(e: WechatMiniprogram.TouchEvent) {
    const imageUrl = e.currentTarget.dataset.url
    if (!imageUrl) {
      wx.showToast({ title: '下载失败', icon: 'none', duration: 2000 })
      return
    }

    wx.showLoading({ title: '下载中...', mask: true })

    wx.downloadFile({
      url: imageUrl,
      success: (res) => {
        if (res.statusCode !== 200) {
          wx.hideLoading()
          wx.showToast({ title: '下载失败', icon: 'none', duration: 2000 })
          return
        }
        wx.saveImageToPhotosAlbum({
          filePath: res.tempFilePath,
          success: () => {
            wx.hideLoading()
            wx.showToast({ title: '保存成功', icon: 'success', duration: 2000 })
          },
          fail: (err) => {
            wx.hideLoading()
            console.error('保存失败:', err)
            wx.showToast({ title: '保存失败', icon: 'none', duration: 2000 })
          },
        })
      },
      fail: (err) => {
        wx.hideLoading()
        console.error('下载失败:', err)
        wx.showToast({ title: '下载失败', icon: 'none', duration: 2000 })
      },
    })
  },

  // 复制文本
  copyText(e: WechatMiniprogram.TouchEvent) {
    const text = e.currentTarget.dataset.text
    if (text === undefined || text === null || text === '') {
      wx.showToast({ title: '复制失败', icon: 'none', duration: 2000 })
      return
    }

    wx.setClipboardData({
      data: String(text),
      success() {
        wx.showToast({ title: '复制成功', icon: 'success', duration: 2000 })
      },
      fail(err) {
        console.error('复制失败:', err)
        wx.showToast({ title: '复制失败', icon: 'none', duration: 2000 })
      },
    })
  },

  // 非 tabBar 页面，可带参数
  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    const url = e.currentTarget.dataset.url
    if (!url) {
      return
    }
    wx.navigateTo({ url })
  },
})
