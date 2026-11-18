/**
 +----------------------------------------------------------
 * 地区选择器
 +----------------------------------------------------------
 */
const { createApp, ref, computed, onMounted } = Vue;

createApp({
  setup() {
    // 响应式数据
    const province_list = ref([]);
    const city_list = ref([]);
    const district_list = ref([]);
    const selectedProvince = ref(null);
    const selectedCity = ref(null);
    const selectedDistrict = ref(null);
    const wrong = ref("");

    // 控制区下拉框显示条件
    const showDistrictSelect = computed(() => {
      return selectedCity.value && district_list.value.length > 0;
    });

    // 初始化
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

          const contactInfo = result.data.contact;
          if (contactInfo) {
            selectedProvince.value = contactInfo.selectedProvince;
            provinceList();

            if (contactInfo.selectedCity) {
              selectedCity.value = contactInfo.selectedCity;
              cityList();
            }

            if (contactInfo.selectedDistrict) {
              selectedDistrict.value = contactInfo.selectedDistrict;
              districtList();
            }
          }
        })
        .catch(() => {
          console.log("初始化失败");
        });
    };

    // 省份列表
    const provinceList = () => {
      fetch(route("user.area"), {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: `type=province&current=${selectedProvince.value || ""}`,
      })
        .then((res) => res.json())
        .then((raw) => {
          const result = douApi(raw);
          if (!result.ok) {
            if (result.message) alert(result.message);
            return;
          }
          if (result.data.area_list) {
            province_list.value = result.data.area_list;
          }
        })
        .catch(() => {
          console.log("获取省份列表失败");
        });
    };

    // 城市列表
    const cityList = () => {
      fetch(route("user.area"), {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: `type=city&parent=${selectedProvince.value || ""}&current=${selectedCity.value || ""}`,
      })
        .then((res) => res.json())
        .then((raw) => {
          const result = douApi(raw);
          if (!result.ok) {
            if (result.message) alert(result.message);
            return;
          }
          if (result.data.area_list) {
            city_list.value = result.data.area_list;
          }
        })
        .catch(() => {
          console.log("获取城市列表失败");
        });
    };

    // 获取区县数据
    const districtList = () => {
      fetch(route("user.area"), {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: `type=district&parent=${selectedCity.value || ""}&current=${selectedDistrict.value || ""}`,
      })
        .then((res) => res.json())
        .then((raw) => {
          const result = douApi(raw);
          if (!result.ok) {
            if (result.message) alert(result.message);
            return;
          }
          if (result.data.area_list) {
            district_list.value = result.data.area_list;
          }
        })
        .catch(() => {
          console.log("获取区县列表失败");
        });
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
        provinceList();
      }
    });

    // 暴露给模板
    return {
      province_list,
      city_list,
      district_list,
      selectedProvince,
      selectedCity,
      selectedDistrict,
      wrong,
      showDistrictSelect,
      provinceList,
      cityList,
      districtList,
      handleProvinceChange,
      handleCityChange,
    };
  },
}).mount("#area");
