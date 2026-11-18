// pages/order/cashier.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'

Page({
  data: {
    orderSn: '',
    payment: '',
    payment_list: [] as Array<Record<string, any>>,
    paying: false,
    payableText: '',
  } as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.loadCashier(that.data.orderSn)
    })
  },

  onLoad(options) {
    this.setData({ orderSn: options && options.order_sn ? options.order_sn : '' })
    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadCashier(orderSn: string) {
    const that = this
    if (!orderSn) return

    http
      .post(route('order.cashier'), {
        order_sn: orderSn,
      })
      .then(function (data) {
        const list = data && Array.isArray(data.payment_list) ? data.payment_list : []
        let selected = ''
        for (let i = 0; i < list.length; i++) {
          if (list[i] && list[i].default) {
            selected = list[i].slug
            break
          }
        }
        if (!selected && list.length > 0) {
          selected = list[0].slug
        }

        that.setData({
          title: data.title,
          order: data.order,
          payableText: data.payable_amount_format || '',
          payment_list: list,
          payment: selected,
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  selectPayment(e: WechatMiniprogram.TouchEvent) {
    if (this.data.paying) {
      return
    }
    const slug = e.currentTarget.dataset.slug
    if (slug) {
      this.setData({ payment: slug })
    }
  },

  submit(e: WechatMiniprogram.TouchEvent) {
    const that = this
    if (that.data.paying) {
      return
    }

    const orderSn = e.currentTarget.dataset.order_sn || that.data.orderSn
    const payId = that.data.payment
    if (!payId) {
      douMsg(that.data.lang.order_payment_select || '请选择支付方式')
      return
    }

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return

      that.setData({ paying: true })

      http
        .post(route('order.cashier.payment'), {
          order_sn: orderSn,
          pay_id: payId,
        })
        .then(function (action) {
          that.handleAction(action, orderSn)
        })
        .catch(function (err) {
          that.setData({ paying: false })
          douMsg(err.message || 'request_failed')
        })
    })
  },

  handleAction(action: Record<string, any>, orderSn: string) {
    const that = this
    const type = action && action.type

    if (type === 'paid') {
      that.setData({ paying: false })
      that.confirmPaid(orderSn)
      return
    }

    if (type === 'wxpay') {
      const params = action && action.pay_params ? action.pay_params : null
      if (!params) {
        that.setData({ paying: false })
        douMsg(that.data.lang.order_pay_failed || '支付失败，请重试')
        return
      }

      wx.requestPayment(
        Object.assign({}, params, {
          success() {
            that.confirmPaid(orderSn)
          },
          fail(err: WechatMiniprogram.GeneralCallbackResult) {
            that.setData({ paying: false })
            const message = (err && err.errMsg) || ''
            if (message.indexOf('cancel') >= 0) {
              douMsg(that.data.lang.order_pay_cancelled || '支付已取消')
            } else {
              douMsg(that.data.lang.order_pay_failed || '支付失败，请重试')
            }
          },
        })
      )
      return
    }

    if (type === 'navigate' && action.page) {
      that.setData({ paying: false })
      let url = String(action.page)
      const query = action.query || {}
      const parts: string[] = []
      const keys = Object.keys(query)
      for (let i = 0; i < keys.length; i++) {
        const key = keys[i]
        const val = query[key]
        if (val === undefined || val === null || val === '') continue
        parts.push(key + '=' + encodeURIComponent(String(val)))
      }
      if (parts.length > 0) {
        url += (url.indexOf('?') >= 0 ? '&' : '?') + parts.join('&')
      }
      wx.navigateTo({ url })
      return
    }

    that.setData({ paying: false })
    douMsg(that.data.lang.order_pay_failed || '支付失败，请重试')
  },

  // 支付结果以服务端为准：客户端回调成功只代表 SDK 拉起成功，需轮询订单状态
  confirmPaid(orderSn: string) {
    const that = this
    const delays = [1000, 1000, 2000, 3000, 4000]
    let attempt = 0

    const tick = function () {
      http
        .get(route('order.cashier.status'), { order_sn: orderSn })
        .then(function (data) {
          if (data.paid) {
            that.setData({ paying: false })
            douMsg('支付成功', '/pages/order/user')
            return
          }
          if (data.finished) {
            that.setData({ paying: false })
            douMsg(that.data.lang.order_pay_cancelled || '支付已取消')
            return
          }
          attempt++
          if (attempt >= delays.length) {
            that.setData({ paying: false })
            douMsg(
              that.data.lang.order_pay_confirming || '支付结果确认中，请稍后在订单列表查看',
              '/pages/order/user'
            )
            return
          }
          setTimeout(tick, delays[attempt])
        })
        .catch(function () {
          attempt++
          if (attempt >= delays.length) {
            that.setData({ paying: false })
            douMsg(
              that.data.lang.order_pay_confirming || '支付结果确认中，请稍后在订单列表查看',
              '/pages/order/user'
            )
            return
          }
          setTimeout(tick, delays[attempt])
        })
    }

    setTimeout(tick, delays[0])
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },

  douSwitchTab(e: WechatMiniprogram.TouchEvent) {
    wx.switchTab({ url: e.currentTarget.dataset.url })
  },
})
