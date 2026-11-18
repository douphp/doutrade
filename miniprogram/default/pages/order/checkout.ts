// pages/order/checkout.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { clearQuoteToken, readQuoteMode, readQuoteToken } from '../../utils/checkout.js'

Page({
  data: {
    contact: '',
    contact_list: [],
    isVisible: false,
    source: 'cart',
    submitting: false,
    submit_token: '',
    quote_token: '',
    quote_mode: 'money',
  } as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.contactList()
    })
  },

  onLoad(options) {
    const that = this
    const source = options.source === 'quote' ? 'quote' : 'cart'
    const quoteToken = source === 'quote' ? readQuoteToken() : ''
    const quoteMode = source === 'quote' ? readQuoteMode() : 'money'

    // 页面级固化报价 token：后续 preview/submit 只用这一份，避免被其它页面的 buy-now 覆盖
    that.setData({
      source,
      quote_token: quoteToken,
      quote_mode: quoteMode,
      submit_token: that.createSubmitToken(),
      mode: quoteMode,
    })

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    if (source === 'quote' && !quoteToken) {
      douMsg(commonStore.lang.order_quote_invalid || '购买信息已失效，请重新下单')
      setTimeout(function () {
        wx.navigateBack({ delta: 1 })
      }, 1200)
      return
    }

    if (options.contact_id) {
      that.setData({ contact_id: options.contact_id })
    }

    that.preview()
    that.contactSelect()
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  createSubmitToken() {
    return Date.now().toString(36) + Math.random().toString(36).slice(2, 10)
  },

  quoteToken() {
    return this.data.quote_token || ''
  },

  preview(extra?: Record<string, any>) {
    const that = this
    const seq = (that.previewSeq || 0) + 1
    that.previewSeq = seq

    const data = Object.assign(
      {
        quote_token: that.quoteToken(),
        contact_id: that.data.contact_id || 0,
        shipping_id: that.data.shipping_id || '',
        coupon_id: that.data.coupon_id || 0,
        mode: that.data.quote_mode || 'money',
      },
      extra || {}
    )

    http
      .post(route('order.checkout.preview'), data)
      .then(function (res) {
        if (that.previewSeq !== seq) {
          return
        }
        if (res.coupon_error) {
          douMsg(res.coupon_error)
        }
        that.setData({
          title: res.title,
          cart: res.cart,
          shipping_list: res.shipping_list,
          shipping_id: res.shipping_id,
          coupon_list: res.coupon_list,
          coupon_id: res.coupon_id,
          amount: res.amount,
          order: res.order,
          mode: res.mode,
        })
      })
      .catch(function (err) {
        if (that.previewSeq !== seq) {
          return
        }
        const reason = err && err.data ? err.data.reason : ''
        if (reason === 'quote_invalid' || reason === 'price_changed') {
          clearQuoteToken(that.quoteToken())
        }
        douMsg(err.message || 'request_failed')
      })
  },

  orderSuccess(e?: WechatMiniprogram.CustomEvent) {
    const that = this
    if (that.data.submitting || !e) {
      return
    }

    const form = e.detail.value || {}

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return

      that.setData({ submitting: true })

      http
        .post(route('order.checkout.checkout_post'), {
          contact_id: that.data.contact_id || 0,
          shipping_id: form.shipping_id || that.data.shipping_id || '',
          coupon_id: form.coupon_id || that.data.coupon_id || 0,
          quote_token: that.quoteToken(),
          submit_token: that.data.submit_token,
          mode: that.data.mode || 'money',
        })
        .then(function (data) {
          clearQuoteToken(that.quoteToken())
          that.setData({ submitting: false })
          wx.redirectTo({ url: data.cashier_url })
        })
        .catch(function (err) {
          that.setData({ submitting: false })
          const reason = err && err.data ? err.data.reason : ''
          if (reason === 'quote_invalid' || reason === 'price_changed') {
            clearQuoteToken(that.quoteToken())
          }
          douMsg(err.message || 'request_failed')
        })
    })
  },

  radioShipping(e: WechatMiniprogram.CustomEvent) {
    const shippingId = e.detail.value
    this.setData({ shipping_id: shippingId })
    this.preview({ shipping_id: shippingId })
  },

  useCoupon(e: WechatMiniprogram.CustomEvent) {
    const couponId = e.detail.value
    this.setData({ coupon_id: couponId })
    this.preview({ coupon_id: couponId })
  },

  contactList() {
    const that = this

    http
      .post(route('user.contact.list_json'), {
        contact_id: that.data.contact_id,
      })
      .then(function (data) {
        that.setData({ contact_list: data.contact_list || [] })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  contactSelect(e?: WechatMiniprogram.TouchEvent) {
    const that = this

    let contact_id = e ? e.currentTarget.dataset.contact_id : ''
    if (!contact_id) {
      contact_id = that.data.contact_id || ''
    }

    http
      .post(route('user.contact.info'), {
        contact_id,
      })
      .then(function (data) {
        if (data.contact) {
          that.setData({ contact: data.contact, contact_id: data.contact.id })
          that.contactList()
          if (contact_id) {
            that.setData({ isVisible: false })
          }
        }
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  contactPopup() {
    // 每次打开都重新拉取列表，保证新增/编辑后的联系人立即可选
    if (!this.data.isVisible) {
      this.contactList()
    }
    this.setData({ isVisible: !this.data.isVisible })
  },

  contactInfo() {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('user.contact.info'), {
        })
        .then(function (data) {
          that.setData({ contact: data.contact || {} })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },

  douRedirectTo(e: WechatMiniprogram.TouchEvent) {
    wx.redirectTo({ url: e.currentTarget.dataset.url })
  },

  douSwitchTab(e: WechatMiniprogram.TouchEvent) {
    wx.switchTab({ url: e.currentTarget.dataset.url })
  },
})
