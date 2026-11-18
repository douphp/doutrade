/**
 +----------------------------------------------------------
 * 地区选择器（国家 / 省 / 市 / 区 四级联动）
 +----------------------------------------------------------
 * 数据统一取 route=user/area：上层一律传 parent_id；
 * 当前分区只有一个顶层节点（单国家）时国家下拉自动隐藏，但值照常提交。
 */
const { createApp, ref, computed, onMounted } = Vue;

createApp({
  setup() {
    // 响应式数据
    const country_list = ref([]);
    const province_list = ref([]);
    const city_list = ref([]);
    const district_list = ref([]);
    const selectedCountry = ref(null);
    const selectedProvince = ref(null);
    const selectedCity = ref(null);
    const selectedDistrict = ref(null);
    const multiple = ref(false);
    const wrong = ref("");

    // 控制国家下拉显示条件：分区内多于一个国家时才需要选择
    const showCountrySelect = computed(() => {
      return multiple.value && country_list.value.length > 0;
    });

    // 控制区下拉框显示条件
    const showDistrictSelect = computed(() => {
      return selectedCity.value && district_list.value.length > 0;
    });

    // 按名称在列表中取 id（接口返回的选项都带 id）
    const idOf = (list, name) => {
      for (let i = 0; i < list.length; i++) {
        if (list[i].name === name) {
          return list[i].id;
        }
      }
      return "";
    };

    const countryId = computed(() => idOf(country_list.value, selectedCountry.value));
    const provinceId = computed(() => idOf(province_list.value, selectedProvince.value));
    const cityId = computed(() => idOf(city_list.value, selectedCity.value));

    // 请求地区接口，成功时返回 data（含 area_list / multiple）
    const fetchArea = (type, parentId, current) => {
      let body = `type=${type}&current=${encodeURIComponent(current || "")}`;
      if (parentId) {
        body += `&parent_id=${encodeURIComponent(parentId)}`;
      }

      return fetch(route("user.area"), {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: body,
      })
        .then((res) => res.json())
        .then((raw) => {
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

    // 国家列表（顶层节点）
    const countryList = () => {
      return fetchArea("root", "", selectedCountry.value).then((data) => {
        country_list.value = data.area_list || [];
        multiple.value = !!data.multiple;
        if (!multiple.value && country_list.value.length === 1) {
          selectedCountry.value = country_list.value[0].name;
        }
      });
    };

    // 省份列表
    const provinceList = () => {
      return fetchArea("province", countryId.value, selectedProvince.value).then((data) => {
        province_list.value = data.area_list || [];
      });
    };

    // 城市列表
    const cityList = () => {
      return fetchArea("city", provinceId.value, selectedCity.value).then((data) => {
        city_list.value = data.area_list || [];
      });
    };

    // 获取区县数据
    const districtList = () => {
      return fetchArea("district", cityId.value, selectedDistrict.value).then((data) => {
        district_list.value = data.area_list || [];
      });
    };

    // 初始化（编辑已有地址时回填）
    const init = () => {
      fetch(route("user.area"), {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: `type=init&id=${id || ""}`,
      })
        .then((res) => res.json())
        .then((raw) => {
          const result = douApi(raw);
          if (!result.ok) {
            if (result.message) alert(result.message);
            return;
          }

          const data = result.data || {};
          const roots = data.roots || [];
          country_list.value = roots;
          multiple.value = !!data.multiple;

          const contactInfo = data.contact;
          if (contactInfo) {
            selectedCountry.value = contactInfo.selectedCountry || "";
            selectedProvince.value = contactInfo.selectedProvince;
            selectedCity.value = contactInfo.selectedCity;
            selectedDistrict.value = contactInfo.selectedDistrict;
          }

          // 单国家分区不需要选国家，但值照常提交
          if (!multiple.value && roots.length === 1) {
            selectedCountry.value = roots[0].name;
          }

          provinceList()
            .then(() => {
              if (selectedProvince.value) {
                return cityList();
              }
            })
            .then(() => {
              if (selectedCity.value) {
                return districtList();
              }
            });
        })
        .catch(() => {
          console.log("初始化失败");
        });
    };

    // 国家选择变化处理
    const handleCountryChange = () => {
      selectedProvince.value = null;
      selectedCity.value = null;
      selectedDistrict.value = null;
      city_list.value = [];
      district_list.value = [];

      if (selectedCountry.value) {
        provinceList();
      } else {
        province_list.value = [];
      }
    };

    // 省份选择变化处理
    const handleProvinceChange = () => {
      selectedCity.value = null;
      selectedDistrict.value = null;
      district_list.value = [];

      if (selectedProvince.value) {
        cityList();
      } else {
        city_list.value = [];
      }
    };

    // 城市选择变化处理
    const handleCityChange = () => {
      selectedDistrict.value = null;
      if (selectedCity.value) {
        districtList();
      } else {
        district_list.value = [];
      }
    };

    // 初始化
    onMounted(() => {
      if (typeof id !== "undefined" && id) {
        init();
      } else {
        countryList().then(() => {
          return provinceList();
        });
      }
    });

    // 暴露给模板
    return {
      country_list,
      province_list,
      city_list,
      district_list,
      selectedCountry,
      selectedProvince,
      selectedCity,
      selectedDistrict,
      multiple,
      wrong,
      showCountrySelect,
      showDistrictSelect,
      countryList,
      provinceList,
      cityList,
      districtList,
      handleCountryChange,
      handleProvinceChange,
      handleCityChange,
    };
  },
}).mount("#area");
