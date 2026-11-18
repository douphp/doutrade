/**
 +----------------------------------------------------------
 * 监听
 +----------------------------------------------------------
 */
$(function() {
    $(".cartBox .minus").click(function() {
        var id = $(this).data("id");
        changeNumber(id, 'minus');
    });
 
    $(".cartBox .plus").click(function() {
        var id = $(this).data("id");
        changeNumber(id, 'plus');
    });
 
    $(".cartBox .inp").change(function() {
        var id = $(this).data("id");
        var number = $(this).val();
        changePrice(id, number);
    });
});

/**
 +----------------------------------------------------------
 * 更新购物车数量
 +----------------------------------------------------------
 */
function changeNumber(id, calculate) {
    var item = document.getElementById("number_" + id);
   
    if (calculate == 'plus') {
        item.value++;
    } else {
        if (item.value > 1) {
            item.value--;
        }
    }
    
    changePrice(id, item.value);
}

/**
 +----------------------------------------------------------
 * 更新购物车价格
 +----------------------------------------------------------
 */
var cartUpdateSeq = {};
function changePrice(id, number) {
    if (number == 0) {
        document.getElementById("number_" + id).value = 1;
        var number = 1;
    }

    var seq = (cartUpdateSeq[id] || 0) + 1;
    cartUpdateSeq[id] = seq;

    function handle(result) {
        // 同一行连续改数量时只接受最后一次响应，避免旧响应覆盖新值
        if (seq !== cartUpdateSeq[id]) {
            return;
        }
        if (!result.ok) {
            if (result.message) {
                alert(result.message);
            }
            return;
        }
        var order = result.data || {};
        $("#number_" + id).val(order.item_number);
        $("#subtotal_" + id).html(order.subtotal);
        $("#total").html(order.total);
        $("#item_amount").html(order.item_amount);
        if (order.msg) {
            alert(order.msg);
        }
    }

    $.ajax({
        type: "POST",
        url: route('order.cart.update'),
        data: {"id":id, "number":number},
        dataType: "json",
        success: function(data) { handle(douApi(data)); },
        error: function(xhr) { handle(douApi(xhr)); }
    });
}

/**
 +----------------------------------------------------------
 * 结算预览（配送 / 优惠券变更统一入口）
 *
 * 同时提交当前配送与优惠券，服务端按同一套 computeTotals 重算；
 * 请求序号防止快速切换时旧响应覆盖新选择。
 +----------------------------------------------------------
 */
var previewSeq = 0;
function previewCheckout() {
    var seq = ++previewSeq;
    var shippingId = $('input[name="shipping_id"]:checked').val() || '';
    var couponId = $('input[name="coupon_id"]:checked').val() || 0;
    var quoteToken = $('input[name="quote_token"]').val() || '';
    var mode = $('input[name="mode"]').val() || 'money';

    function handle(result) {
        if (seq !== previewSeq) {
            return;
        }
        if (!result.ok) {
            if (result.message) {
                alert(result.message);
            }
            return;
        }
        var order = result.data || {};
        if (order.coupon_error) {
            alert(order.coupon_error);
        }
        $("#shipping_fee").html(order.shipping_fee);
        $("#coupon_amount").html(order.coupon_amount);
        $(".order_amount").html(order.order_amount);
    }

    $.ajax({
        type: "POST",
        url: route('order.checkout.preview'),
        data: {"shipping_id": shippingId, "coupon_id": couponId, "quote_token": quoteToken, "mode": mode},
        dataType: "json",
        success: function(data) { handle(douApi(data)); },
        error: function(xhr) { handle(douApi(xhr)); }
    });
}

/**
 +----------------------------------------------------------
 * 收银台：余额勾选联动金额汇总
 *
 * 1. 余额抵扣行只在勾选时出现（没有抵扣不展示优惠行）；
 * 2. 应付金额取服务端下发的两套金额之一；
 * 3. 余额已付清时不需再选渠道，隐藏支付方式并改为「确认支付」。
 +----------------------------------------------------------
 */
function syncCashierAmount(form) {
    if (!form) {
        return;
    }
    var toggle = form.querySelector('#use_wallet');
    if (!toggle) {
        return;
    }
    var dataset = form.dataset || {};
    var useWallet = !!toggle.checked;
    var settled = useWallet && (dataset.walletFull || '') === '1';
    var i;

    var deductRows = form.querySelectorAll('.js-wallet-deduct-row');
    for (i = 0; i < deductRows.length; i++) {
        deductRows[i].style.display = useWallet ? '' : 'none';
    }

    var payableNodes = form.querySelectorAll('.js-gateway-remain');
    var payableText = useWallet ? (dataset.gatewayOn || '') : (dataset.gatewayOff || '');
    for (i = 0; i < payableNodes.length; i++) {
        payableNodes[i].innerHTML = payableText;
    }

    var paymentBoxes = form.querySelectorAll('.js-payment-box');
    for (i = 0; i < paymentBoxes.length; i++) {
        paymentBoxes[i].style.display = settled ? 'none' : '';
    }

    var btn = form.querySelector('.submit');
    if (btn && !btn.disabled && dataset.btnPay && dataset.btnConfirm) {
        btn.value = settled ? dataset.btnConfirm : dataset.btnPay;
    }
}

$(function() {
    var cashier = document.getElementById('cashierForm');
    if (!cashier) {
        return;
    }
    var toggle = cashier.querySelector('#use_wallet');
    if (!toggle) {
        return;
    }
    toggle.addEventListener('change', function() {
        syncCashierAmount(cashier);
    });
    syncCashierAmount(cashier);
});

/**
 +----------------------------------------------------------
 * 收银台表单提交：POST /order/charge，由后端创建 dou_order_payment 行并调起第三方
 +----------------------------------------------------------
 */
function cashierForm(form) {
    var checked = form.querySelector('input[name="pay_id"]:checked');
    // 余额抵扣由勾选框独立控制；无可用网关时勾选余额也允许提交（纯余额支付）
    var useWallet = form.querySelector('#use_wallet');
    if (!checked && !(useWallet && useWallet.checked)) {
        return;
    }
    // 防重复点击：通过校验后立即禁用提交按钮再提交
    var btn = form.querySelector('.submit');
    if (btn) {
        if (btn.disabled) {
            return;
        }
        btn.disabled = true;
        btn.classList.add('loading');
    }
    form.submit();
}

/**
 +----------------------------------------------------------
 * 联系人选择
 +----------------------------------------------------------
 */
if (document.getElementById("checkout")) {
    const { createApp, ref, reactive, computed, onMounted } = Vue;

    createApp({
        setup() {
            // 响应式数据
            const contact = ref('');
            const contact_info = ref([]);
            const contact_list = ref([]);
            const isVisible = ref(false);
            const actionType = ref('list');
            const formAction = ref('store');
            const formData = reactive({
                id: 0,
                name: '',
                first_name: '',
                last_name: '',
                phone: '',
                country: '',
                province: '',
                city: '',
                district: '',
                address: '',
                postcode: '',
                tag: '',
                is_default: false,
                token: ''
            });
            const wrong = ref('');

            /* ----------- 地区选择器 start ------------ */
            const country_list = ref([]);
            const province_list = ref([]);
            const city_list = ref([]);
            const district_list = ref([]);
            const selectedProvince = ref(null);
            const selectedCity = ref(null);
            const selectedDistrict = ref(null);
            const areaMultiple = ref(false);

            // 控制国家下拉显示条件：分区内多于一个国家时才需要选择
            const showCountrySelect = computed(() => {
                return areaMultiple.value && country_list.value.length > 0;
            });

            // 控制区下拉框显示条件
            const showDistrictSelect = computed(() => {
                return district_list.value.length > 0;
            });

            // 按名称在列表中取 id（接口返回的选项都带 id）
            const areaIdOf = (list, name) => {
                for (let i = 0; i < list.length; i++) {
                    if (list[i].name === name) {
                        return list[i].id;
                    }
                }
                return '';
            };
            /* ----------- 地区选择器 end ------------ */
            
            // 联系人列表
            const contactList = () => {
                fetch(route('user.contact.list_json'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `contact_id=${contact.value.id || ''}`
                })
                .then(res => res.json())
                .then(raw => {
                    const result = douApi(raw);
                    if (!result.ok) {
                        if (result.message) alert(result.message);
                        return;
                    }
                    if (result.data.contact_list) {
                        contact_list.value = result.data.contact_list;
                    }
                })
                .catch(() => {
                    console.log('失败');
                });
            };

            // 选择联系人
            const contactSelect = (contact_id, actionPuput = true) => {
                fetch(route('user.contact.info'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `contact_id=${contact_id || ''}`
                })
                .then(res => res.json())
                .then(raw => {
                    const result = douApi(raw);
                    if (!result.ok) {
                        if (result.message) alert(result.message);
                        return;
                    }
                    if (result.data.contact) {
                        contact.value = result.data.contact;
                        contactList();
                        if (contact_id && actionPuput) {
                            contactPopup();
                        }
                    }
                })
                .catch(() => {
                    console.log('失败');
                });
            };

            // 联系人详情
            const contactInfo = (contact_id) => {
                fetch(route('user.contact.info'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `contact_id=${contact_id}`
                })
                .then(res => res.json())
                .then(raw => {
                    const result = douApi(raw);
                    if (!result.ok) {
                        if (result.message) alert(result.message);
                        return;
                    }
                    formData.id = contact_id;
                    if (result.data.contact) {
                        Object.assign(formData, result.data.contact);
                    }
                })
                .catch(() => {
                    console.log('失败');
                });
            };

            // 联系人操作框
            const contactPopup = (type, contact_id) => {
                isVisible.value = !isVisible.value;
                actionType.value = type;

                if (type === 'add' || type === 'edit') {
                    if (contact_id) {
                        console.log(contact_id);
                        contactInfo(contact_id);
                        area_init(contact_id);
                    } else {
                        Object.assign(formData, {
                            id: 0,
                            name: '',
                            first_name: '',
                            last_name: '',
                            phone: '',
                            country: '',
                            province: '',
                            city: '',
                            district: '',
                            address: '',
                            postcode: '',
                            tag: '',
                            is_default: false,
                            token: ''
                        });
                        areaRootList();
                        city_list.value = [];
                        district_list.value = [];
                    }

                    formAction.value = type === 'add' ? 'store' : 'update';
                }
            };

            /**
             * 结算单提交（单次 POST + 内容协商）。
             *
             * 走 Accept: application/json，服务端命中 wantsJson() 后返回标准 envelope，
             * 业务错误以 422 + DomainException 渲染（dou.js douApi 解析），成功 envelope
             * 的 data.redirect_url 决定跳转：零元订单 → 订单列表；正额订单 → 收银台；
             * 非金额订单（积分/拼团等）→ 订单详情。详见 CheckoutController::success()。
             */
            const submitCheckout = (event) => {
                const form = event && event.target && event.target.tagName === 'FORM'
                    ? event.target
                    : document.getElementById('checkout');
                if (!form) {
                    return;
                }
                const submitBtn = form.querySelector('input[type="submit"], button[type="submit"], input.submit');
                if (submitBtn) {
                    submitBtn.disabled = true;
                }

                fetch(form.getAttribute('action'), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new FormData(form),
                    credentials: 'same-origin'
                })
                    .then((res) => res.json().catch(() => null))
                    .then((raw) => {
                        const result = douApi(raw);
                        if (result.ok) {
                            const url = result.data && result.data.redirect_url ? result.data.redirect_url : '';
                            if (url) {
                                window.location.href = url;
                                return;
                            }
                        }
                        if (submitBtn) {
                            submitBtn.disabled = false;
                        }
                        if (result.message) {
                            alert(result.message);
                            return;
                        }
                        const errors = result.errors || {};
                        for (const key in errors) {
                            if (errors.hasOwnProperty(key) && errors[key]) {
                                alert(errors[key]);
                                return;
                            }
                        }
                        if (!result.ok) {
                            alert('error');
                        }
                    })
                    .catch(() => {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                        }
                        alert('error');
                    });
            };

            // 添加或编辑
            const contactForm = () => {
                const tokenEl = document.querySelector('[name="token"]');
                if (tokenEl) {
                    formData.token = tokenEl.value;
                }

                const params = new URLSearchParams();
                // 只提交表单字段，避免把 id/is_default 等残留值带到新增请求
                ['name', 'first_name', 'last_name', 'phone', 'country', 'province', 'city', 'district', 'address', 'postcode', 'tag'].forEach(key => {
                    params.append(key, formData[key] == null ? '' : formData[key]);
                });
                params.append('is_default', formData.is_default ? '1' : '0');
                params.append('token', formData.token || '');

                // RESTful 资源路径：新增 POST user/contact；编辑 PUT user/contact/{id}（_method 伪装）
                const isEdit = formAction.value === 'update';
                let target = route('user.contact.store', {}, { query: { do: 'vue' } });
                if (isEdit) {
                    params.append('_method', 'PUT');
                    target = route('user.contact.update', { id: formData.id }, { query: { do: 'vue' } });
                }

                fetch(target, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: params.toString()
                })
                .then(res => res.json())
                .then(raw => {
                    const result = douApi(raw);
                    if (!result.ok) {
                        if (result.message) alert(result.message);
                        return;
                    }
                    contactSelect(result.data.contact_id, false);
                    contactList();
                    isVisible.value = false;
                })
                .catch(error => {
                    console.error('提交失败', error);
                });
            };

            /* ----------- 地区选择器 start ------------ */
            // 请求地区接口，成功时返回 data（含 area_list / multiple）
            const areaFetch = (type, parentId, current) => {
                let body = `type=${type}&current=${encodeURIComponent(current || '')}`;
                if (parentId) {
                    body += `&parent_id=${encodeURIComponent(parentId)}`;
                }

                return fetch(route('user.area'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body
                })
                .then(res => res.json())
                .then(raw => {
                    const result = douApi(raw);
                    if (!result.ok) {
                        if (result.message) alert(result.message);
                        return {};
                    }
                    return result.data || {};
                })
                .catch(() => {
                    return {};
                });
            };

            // 国家列表（顶层节点），单国家时自动回填国家名
            const areaRootList = () => {
                return areaFetch('root', '', formData.country).then(data => {
                    country_list.value = data.area_list || [];
                    areaMultiple.value = !!data.multiple;

                    if (!areaMultiple.value && country_list.value.length === 1) {
                        formData.country = country_list.value[0].name;
                    }

                    return provinceList();
                });
            };

            // 省份列表（上级为国家）
            const provinceList = () => {
                return areaFetch('province', areaIdOf(country_list.value, formData.country), formData.province).then(data => {
                    province_list.value = data.area_list || [];
                });
            };

            // 城市列表（上级为省）
            const cityList = () => {
                return areaFetch('city', areaIdOf(province_list.value, formData.province), formData.city).then(data => {
                    city_list.value = data.area_list || [];
                });
            };

            // 获取区县数据（上级为市）
            const districtList = () => {
                return areaFetch('district', areaIdOf(city_list.value, formData.city), formData.district).then(data => {
                    district_list.value = data.area_list || [];
                });
            };

            // 初始化（编辑已有地址时回填）
            const area_init = (contact_id) => {
                fetch(route('user.area'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `type=init&id=${contact_id}`
                })
                .then(res => res.json())
                .then(raw => {
                    const result = douApi(raw);
                    if (!result.ok) {
                        if (result.message) alert(result.message);
                        return;
                    }

                    const data = result.data || {};
                    const roots = data.roots || [];
                    country_list.value = roots;
                    areaMultiple.value = !!data.multiple;

                    const contactInfo = data.contact;
                    if (contactInfo) {
                        formData.country = contactInfo.selectedCountry || '';
                        formData.province = contactInfo.selectedProvince;
                        formData.city = contactInfo.selectedCity;
                        formData.district = contactInfo.selectedDistrict;
                    }

                    // 单国家分区不需要选国家，但值照常提交
                    if (!areaMultiple.value && roots.length === 1) {
                        formData.country = roots[0].name;
                    }

                    provinceList()
                        .then(() => {
                            if (formData.province) {
                                return cityList();
                            }
                        })
                        .then(() => {
                            if (formData.city) {
                                return districtList();
                            }
                        });
                })
                .catch(() => {
                    console.log('area init failed');
                });
            };

            // 国家选择变化处理
            const handleCountryChange = () => {
                formData.province = null;
                formData.city = null;
                formData.district = null;
                province_list.value = [];
                city_list.value = [];
                district_list.value = [];

                if (formData.country) {
                    provinceList();
                }
            };

            // 省份选择变化处理
            const handleProvinceChange = () => {
                formData.city = null;
                formData.district = null;
                city_list.value = [];
                district_list.value = [];

                if (formData.province) {
                    cityList();
                }
            };

            // 城市选择变化处理
            const handleCityChange = () => {
                formData.district = null;
                district_list.value = [];

                if (formData.city) {
                    districtList();
                }
            };
            /* ----------- 地区选择器 end ------------ */

            // 初始化
            onMounted(() => {
                contactSelect();
                contactList();
            });

            // 暴露给模板
            return {
                contact,
                contact_info,
                contact_list,
                isVisible,
                actionType,
                formAction,
                formData,
                wrong,
                country_list,
                province_list,
                city_list,
                district_list,
                selectedProvince,
                selectedCity,
                selectedDistrict,
                showCountrySelect,
                showDistrictSelect,
                contactList,
                contactSelect,
                contactInfo,
                contactPopup,
                contactForm,
                submitCheckout,
                area_init,
                areaRootList,
                provinceList,
                cityList,
                districtList,
                handleCountryChange,
                handleProvinceChange,
                handleCityChange
            };
        }
    }).mount(".checkout");
}