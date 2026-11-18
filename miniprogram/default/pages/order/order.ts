// pages/order/order.ts - 购物车
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { clearQuoteCache } from '../../utils/checkout.js'

Page({
  data: {
    startX: 0,
    startY: 0,
  } as Record<string, any>,

  onShow() {
    const that = this
    // 进入购物车即代表按车结算，清掉可能残留的立即购买报价单
    clearQuoteCache()
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      cartStore.refresh()
      that.loadData()
    })
  },

  onLoad() {
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

  loadData() {
    const that = this

    http
      .get(route('order'), {
      })
      .then(function (data) {
        that.setData({ title: data.title, cart: data.cart })
        if (typeof data.cart_number === 'number') {
          cartStore.setNumber(data.cart_number)
        }
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  changeNumber(e: WechatMiniprogram.TouchEvent) {
    const id = e.currentTarget.dataset.id
    const action = e.currentTarget.dataset.action
    const cart = this.data.cart

    if (action === 'plus' && cart && cart.list) {
      for (let i = 0; i < cart.list.length; i++) {
        if (String(cart.list[i].id) === String(id)) {
          const item = cart.list[i]
          if (typeof item.max_number === 'number' && item.number >= item.max_number) {
            douMsg((this.data.lang.order_cart_stock_shortage || '库存不足，最多可购 ') + item.max_number)
            return
          }
          break
        }
      }
    }

    this.updateNumber(id, action)
  },

  inputNumber(e: WechatMiniprogram.CustomEvent) {
    const id = e.currentTarget.dataset.id
    const number = parseInt(e.detail.value, 10)
    if (!id || !number || number < 1) {
      this.loadData()
      return
    }
    this.updateNumber(id, 'input', number)
  },

  updateNumber(id: number | string, action: string, number?: number) {
    const that = this
    // 同一行连续改数量时只接受最后一次响应，避免旧响应覆盖新值
    that.updateSeq = that.updateSeq || {}
    const seq = (that.updateSeq[id] || 0) + 1
    that.updateSeq[id] = seq

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .put(route('order.cart.update', { id }), {
          action,
          id,
          item_number: action === 'input' ? number : '',
        })
        .then(function (data) {
          if (that.updateSeq[id] !== seq) {
            return
          }
          if (!data || typeof data.item_number !== 'number') {
            that.loadData()
            return
          }

          const cart = that.data.cart
          if (!cart || !cart.list) {
            that.loadData()
            return
          }

          for (let i = 0; i < cart.list.length; i++) {
            if (String(cart.list[i].id) === String(id)) {
              cart.list[i].number = data.item_number
              if (data.subtotal) {
                cart.list[i].subtotal = data.subtotal
              }
              break
            }
          }
          cart.total = data.total
          cart.item_amount_format = data.item_amount

          that.setData({ cart })
          if (typeof data.cart_number === 'number') {
            cartStore.setNumber(data.cart_number)
          }
          if (data.msg) {
            douMsg(data.msg)
          }
        })
        .catch(function (err) {
          if (that.updateSeq[id] !== seq) {
            return
          }
          douMsg(err.message || 'request_failed')
          that.loadData()
        })
    })
  },

  itemDel(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const id = e.currentTarget.dataset.id

    wx.showModal({
      title: that.data.lang.order_cart || '购物车',
      content: that.data.lang.order_cart_del_confirm || '确定删除该商品？',
      success(res) {
        if (res.confirm) {
          that.deleteItem(id)
        }
      },
    })
  },

  deleteItem(id: number | string) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .del(route('order.cart.destroy', { id }), {
          id,
        })
        .then(function (data) {
          if (data && typeof data.cart_number === 'number') {
            cartStore.setNumber(data.cart_number)
          }
          that.loadData()
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  clearInvalid() {
    const that = this

    wx.showModal({
      title: that.data.lang.order_cart_invalid || '失效商品',
      content: that.data.lang.order_cart_clear_invalid || '清空失效商品',
      success(res) {
        if (!res.confirm) {
          return
        }
        http
          .post(route('order.cart.clear_invalid'), {})
          .then(function (data) {
            if (data && typeof data.cart_number === 'number') {
              cartStore.setNumber(data.cart_number)
            }
            that.loadData()
          })
          .catch(function (err) {
            douMsg(err.message || 'request_failed')
          })
      },
    })
  },

  touchStart(e: WechatMiniprogram.TouchEvent) {
    this.setData({
      startX: e.changedTouches[0].clientX,
      startY: e.changedTouches[0].clientY,
    })
  },

  touchMove(e: WechatMiniprogram.TouchEvent) {
    const that = this

    const index = e.currentTarget.dataset.index
    const cart = that.data.cart

    const startX = that.data.startX
    const startY = that.data.startY
    const touchMoveX = e.changedTouches[0].clientX
    const touchMoveY = e.changedTouches[0].clientY
    let moveWidth = startX - touchMoveX

    const angle = that.angle({ X: startX, Y: startY }, { X: touchMoveX, Y: touchMoveY })

    if (Math.abs(angle) > 45) return

    let touchMove
    if (cart['list'][index].touch_move_start == 80) {
      if (moveWidth > 0) {
        return
      } else {
        touchMove = cart['list'][index].touch_move_start + moveWidth
      }
    } else {
      if (moveWidth > 80) moveWidth = 80
      if (moveWidth < 0) moveWidth = 0
      touchMove = moveWidth
    }

    cart['list'][index].touch_move = touchMove

    that.setData({ cart })
  },

  touchEnd(e: WechatMiniprogram.TouchEvent) {
    const that = this

    const index = e.currentTarget.dataset.index
    const cart = that.data.cart

    const startX = that.data.startX
    const startY = that.data.startY
    const touchMoveX = e.changedTouches[0].clientX
    const touchMoveY = e.changedTouches[0].clientY
    let moveWidth = startX - touchMoveX

    const angle = that.angle({ X: startX, Y: startY }, { X: touchMoveX, Y: touchMoveY })

    if (Math.abs(angle) > 45) return

    if (moveWidth >= 0) {
      if (moveWidth > 20) {
        moveWidth = 80
      } else {
        moveWidth = 0
      }
    } else {
      if (moveWidth < -20) {
        moveWidth = 0
      } else {
        moveWidth = 80
      }
    }

    cart['list'][index].touch_move = moveWidth
    cart['list'][index].touch_move_start = moveWidth

    that.setData({ cart })
  },

  angle(start: { X: number; Y: number }, end: { X: number; Y: number }) {
    const _X = end.X - start.X
    const _Y = end.Y - start.Y
    return (360 * Math.atan(_Y / _X)) / (2 * Math.PI)
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },

  douSwitchTab(e: WechatMiniprogram.TouchEvent) {
    wx.switchTab({ url: e.currentTarget.dataset.url })
  },
})
