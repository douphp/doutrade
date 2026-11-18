// pages/user/contact_create.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, douPageTo, showShareMenu } from '../../utils/ui.js'
import { readQuoteToken } from '../../utils/checkout.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    isDefault: false,
    country_list: [],
    multiple: false,
    selectedCountry: '',
  } as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.areaRoot()
    })
  },

  onLoad(options) {
    const that = this

    that.setData({ title: pageTitle('user_contact_create') })

    wx.setStorageSync('shareTitle', pageTitle('user_contact_create'))
    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    if (options.from) {
      that.setData({ from: options.from })
    }
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  contactAction(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('user.contact.store'), {
          name: e.detail.value.name,
          phone: e.detail.value.phone,
          id_card: e.detail.value.id_card || '',
          country: that.data.selectedCountry || '',
          province: that.data.selectedProvince || '',
          city: that.data.selectedCity || '',
          district: that.data.selectedDistrict || '',
          address: e.detail.value.address,
          tag: e.detail.value.tag,
          is_default: e.detail.value.is_default,
        })
        .then(function (data) {
          if (that.data.from == 'checkout') {
            const quote = readQuoteToken() ? '&source=quote' : ''
            wx.redirectTo({ url: '/pages/order/checkout?contact_id=' + data.contact_id + quote })
          } else {
            douPageTo('/pages/user/contact')
          }
        })
        .catch(function (err) {
          douMsg(err.message || '')
        })
    })
  },

  /**
   * 地区顶层：单国家自动选中并隐藏国家选择，多国家交给用户选择。
   */
  areaRoot() {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('user.area'), {
          type: 'root',
          current: that.data.selectedCountry || '',
        })
        .then(function (data) {
          const roots = data.area_list || []
          const multiple = !!data.multiple
          const country = multiple ? that.data.selectedCountry : (roots.length === 1 ? roots[0].name : '')

          that.setData({
            country_list: roots,
            multiple,
            selectedCountry: country || '',
          })

          if (country) {
            that.provinceList()
          }
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  provinceList() {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('user.area'), {
          type: 'province',
          parent: that.data.selectedCountry || '',
          current: that.data.selectedProvince || '',
        })
        .then(function (data) {
          that.setData({ province_list: data.area_list || [] })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  handleCountryChange(e: WechatMiniprogram.CustomEvent) {
    const country = this.data.country_list[Number(e.detail.value)]

    this.setData({
      selectedCountry: country ? country.name : '',
      selectedProvince: '',
      selectedCity: '',
      selectedDistrict: '',
      province_list: [],
      city_list: [],
      district_list: [],
    })

    if (country) {
      this.provinceList()
    }
  },

  handleProvinceChange(e: WechatMiniprogram.CustomEvent) {
    const province = this.data.province_list[Number(e.detail.value)]

    this.setData({
      selectedProvince: province ? province.name : '',
      selectedCity: '',
      selectedDistrict: '',
      city_list: [],
      district_list: [],
    })

    if (province) {
      this.cityList()
    }
  },

  cityList() {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('user.area'), {
          type: 'city',
          parent: that.data.selectedProvince || '',
          current: '',
        })
        .then(function (data) {
          that.setData({ city_list: data.area_list || [] })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  handleCityChange(e: WechatMiniprogram.CustomEvent) {
    const city = this.data.city_list[Number(e.detail.value)]

    this.setData({
      selectedCity: city ? city.name : '',
      selectedDistrict: '',
      district_list: [],
    })

    if (city) {
      this.districtList()
    }
  },

  districtList() {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('user.area'), {
          type: 'district',
          parent: that.data.selectedCity || '',
          current: '',
        })
        .then(function (data) {
          that.setData({ district_list: data.area_list || [] })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  handleDistrictChange(e: WechatMiniprogram.CustomEvent) {
    const district = this.data.district_list[Number(e.detail.value)]

    this.setData({ selectedDistrict: district ? district.name : '' })
  },

  onCheckboxChange(e: WechatMiniprogram.CustomEvent) {
    const isChecked = e.detail.value.includes('1')
    this.setData({ isDefault: isChecked })
  },

  douPageTo(e: WechatMiniprogram.TouchEvent) {
    douPageTo(e.currentTarget.dataset.url)
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
