// pages/user/edit.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'

Page({
  data: {
    avatarTemp: '',
    country_list: [],
    province_list: [],
    city_list: [],
    district_list: [],
    multiple: false,
    selectedCountry: '',
    selectedProvince: '',
    selectedCity: '',
    selectedDistrict: '',
  } as Record<string, any>,

  onShow() {
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      cartStore.refresh()
    })
  },

  onLoad() {
    const that = this

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return

      http
        .get(route('user.edit'), {
        })
        .then(function (data) {
          const dou = data.dou || { user: {} }
          that.setData({
            title: data.title,
            language: data.language || '',
            dou,
            sex: dou.user.sex === undefined || dou.user.sex === null ? '' : String(dou.user.sex),
            selectedCountry: dou.user.country || '',
            selectedProvince: dou.user.province || '',
            selectedCity: dou.user.city || '',
            selectedDistrict: dou.user.district || '',
          })

          if (that.data.features && that.data.features.area) {
            that.areaRoot()
          }
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

  userEdit(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return

      const form = e.detail.value as Record<string, any>
      const payload: Record<string, any> = {
        contact: form.contact,
        phone: form.phone,
        address: form.address,
        postcode: form.postcode,
        nickname: form.nickname,
      }

      // 未选择性别时不提交，避免命中 in:0,1 校验或误清空原值
      if (form.sex !== undefined && form.sex !== '') {
        payload.sex = form.sex
      }

      // 地区四层仅在地区模块启用时提交，避免清空历史数据
      if (that.data.features && that.data.features.area) {
        payload.country = that.data.selectedCountry || ''
        payload.province = that.data.selectedProvince || ''
        payload.city = that.data.selectedCity || ''
        payload.district = that.data.selectedDistrict || ''
      }

      if (that.data.language === 'en_us') {
        payload.first_name = form.first_name
        payload.last_name = form.last_name
      }

      http
        .post(route('user.edit_post'), payload)
        .then(function (data) {
          douMsg((data as any).__message || that.data.lang.user_edit_success)

          setTimeout(function () {
            wx.switchTab({ url: '/pages/user/user' })
          }, 2000)
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  /**
   * 地区顶层：单国家自动选中并隐藏国家选择，多国家交给用户选择。
   */
  areaRoot() {
    const that = this

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
  },

  /**
   * 省份列表：有回填值时继续加载市。
   */
  provinceList() {
    const that = this

    http
      .post(route('user.area'), {
        type: 'province',
        parent: that.data.selectedCountry || '',
        current: that.data.selectedProvince || '',
      })
      .then(function (data) {
        that.setData({ province_list: data.area_list || [] })
        if (that.data.selectedProvince) {
          that.cityList()
        }
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  /**
   * 城市列表：有回填值时继续加载区县。
   */
  cityList() {
    const that = this

    http
      .post(route('user.area'), {
        type: 'city',
        parent: that.data.selectedProvince || '',
        current: that.data.selectedCity || '',
      })
      .then(function (data) {
        that.setData({ city_list: data.area_list || [] })
        if (that.data.selectedCity) {
          that.districtList()
        }
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  /**
   * 区县列表。
   */
  districtList() {
    const that = this

    http
      .post(route('user.area'), {
        type: 'district',
        parent: that.data.selectedCity || '',
        current: that.data.selectedDistrict || '',
      })
      .then(function (data) {
        that.setData({ district_list: data.area_list || [] })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
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

  handleDistrictChange(e: WechatMiniprogram.CustomEvent) {
    const district = this.data.district_list[Number(e.detail.value)]
    this.setData({ selectedDistrict: district ? district.name : '' })
  },

  uploadAvatar() {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      wx.chooseImage({
        count: 1,
        sizeType: ['original', 'compressed'],
        sourceType: ['album', 'camera'],
        success(res) {
          that.setData({ avatarTemp: res.tempFilePaths })

          const tempFilePaths = res.tempFilePaths

          wx.uploadFile({
            url: route('user.upload_avatar'),
            filePath: tempFilePaths[0],
            formData: {
            },
            header: {
              Authorization: 'Bearer ' + (wx.getStorageSync('api_token') || ''),
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            name: 'avatar',
            success() {},
          })
        },
      })
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },

  radioSex(e: WechatMiniprogram.CustomEvent) {
    this.setData({ sex: e.detail.value })
  },
})
