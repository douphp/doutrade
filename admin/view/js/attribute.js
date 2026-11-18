/**
 +----------------------------------------------------------
 * 款式型号提交
 +----------------------------------------------------------
 */
var attDraftIndex = 0;

function attAction(mode, module, item_id, att_id, value_id) {
    var target = $('#' + att_id + 'Html');
    var value = $('#' + att_id + 'Value').val();
    var remark = $('#' + att_id + 'Remark').val();
    var price_change = $('#' + att_id + 'PriceChange').val();

    if (!item_id) {
        alert(lang('attribute_value_must_edit_mode', '只有在编辑模式下才可以添加规格属性'));
        return;
    }

    $.ajax({
        type: "POST",
        url: route('admin.attribute.value', {}, { query: { act: mode } }),
        data: {'module':module, 'item_id':item_id, 'att_id':att_id, 'value':value, 'remark':remark, 'price_change':price_change, 'value_id':value_id || ''},
        dataType: 'json',
        success: function(resp) {
            if (resp && resp.data && resp.data.html) {
                target.html(resp.data.html);
                attRestoreLangCur(target);
            }
        },
        error: function(xhr) {
            var resp = xhr.responseJSON;
            if (resp && resp.message) {
                alert(resp.message);
                if (resp.data && resp.data.html) {
                    target.html(resp.data.html);
                    attRestoreLangCur(target);
                }
                return;
            }
            console.log(xhr);
            alert(lang('system_error', '系统错误，请联系管理员！'));
        }
    });
}

/**
 * 新增页：暂存一个待入库的规格选项（随商品表单提交）
 */
function attDraftAdd(att_id) {
    var list = $('#' + att_id + 'Html');
    var value = $('#' + att_id + 'Value').val();
    var remark = $('#' + att_id + 'Remark').val();
    var price_change = $.trim($('#' + att_id + 'PriceChange').val());

    if ($.trim(value) === '') {
        alert(lang('attribute_value_value', '选项') + lang('is_empty', '不能为空'));
        return;
    }
    if (price_change !== '' && !/^[0-9-]+$/.test(price_change)) {
        alert(lang('illegal', '数据格式不正确'));
        return;
    }

    var duplicated = false;
    list.children('li').each(function() {
        if ($(this).attr('data-value') === value) {
            duplicated = true;
        }
    });
    if (duplicated) {
        alert(lang('is_exist', '已存在'));
        return;
    }
    if (!list.children('li').length && parseFloat(price_change) > 0) {
        alert(lang('attribute_value_price_cue', '每个规格属性的“第一个值”不能设置价格浮动'));
        return;
    }

    var tpl = $('#att_' + att_id + '_Tpl').html();
    if (!tpl) {
        return;
    }

    var idx = ++attDraftIndex;
    list.append(tpl.replace(/__IDX__/g, idx));

    var li = list.children('li').last();
    li.attr('data-value', value).attr('title', remark);
    li.find('span.value').text(value + attPriceFormat(price_change));
    li.find('input[name="attribute_value[' + att_id + '][' + idx + '][value]"]').val(value);
    li.find('input[name="attribute_value[' + att_id + '][' + idx + '][remark]"]').val(remark);
    li.find('input[name="attribute_value[' + att_id + '][' + idx + '][price_change]"]').val(price_change);

    $('#' + att_id + 'Value').val('');
    $('#' + att_id + 'Remark').val('');
    $('#' + att_id + 'PriceChange').val('');
}

/**
 * 新增页：移除待入库选项，并清理抽屉写入的译文草稿
 */
function attDraftDel(el) {
    var li = $(el).closest('li');
    var att_id = li.attr('data-att-id');
    var idx = li.attr('data-index');
    var form = li.closest('form');

    li.remove();
    if (att_id && idx && form.length) {
        form.find('input[name^="attribute_value_lang[' + att_id + '][' + idx + ']"]').remove();
    }
}

/**
 * AJAX 回填后恢复草稿译文的 cur 高亮（服务端只认识已入库译文）
 */
function attRestoreLangCur(target) {
    var form = target.closest('form');
    target.find('a[data-draft-target]').each(function() {
        var name = $(this).attr('data-draft-target');
        var input = form.find('[name]').filter(function() {
            return this.name === name;
        });
        if (!input.length) {
            return;
        }
        if ($.trim(input.first().val()) !== '') {
            $(this).addClass('cur');
        } else {
            $(this).removeClass('cur');
        }
    });
}

/**
 * 新增页：按商品分类刷新属性区（切换分类即清空已填选项）
 */
function attInitCategoryBox() {
    var box = $('#attributeBox');
    if (!box.length || box.attr('data-mode') !== 'create') {
        return;
    }

    var select = $('select[name="category_id"]');
    if (!select.length) {
        return;
    }

    var current = String(select.val() || '0');
    var module = String(box.attr('data-module') || 'product');

    select.on('change', function() {
        var next = String($(this).val() || '0');
        if (next === current) {
            return;
        }

        if (box.find('li.att-pending').length
            && !window.confirm(lang('attribute_value_category_switch_confirm', '切换分类将清空已填规格选项，是否继续？'))) {
            select.val(current);
            return;
        }

        var selectEl = $(this);
        selectEl.prop('disabled', true);

        $.ajax({
            type: 'GET',
            cache: false,
            url: route('admin.attribute.box', {}, { query: { module: module, category_id: next } }),
            dataType: 'json',
            success: function(resp) {
                if (!resp || !resp.data || !resp.data.html) {
                    selectEl.prop('disabled', false).val(current);
                    return;
                }

                var form = box.closest('form');
                form.find('input[name^="attribute_value_lang["]').remove();

                var holder = document.createElement('div');
                holder.innerHTML = resp.data.html;
                var newBox = holder.querySelector('#attributeBox');
                if (!newBox) {
                    selectEl.prop('disabled', false).val(current);
                    return;
                }

                box[0].parentNode.replaceChild(newBox, box[0]);
                box = $(newBox);
                current = next;
                selectEl.prop('disabled', false);
            },
            error: function(xhr) {
                var resp = xhr.responseJSON;
                alert(resp && resp.message ? resp.message : lang('system_error', '系统错误，请联系管理员！'));
                selectEl.prop('disabled', false).val(current);
            }
        });
    });
}

/**
 * @param {string} price_change
 * @returns {string}
 */
function attPriceFormat(price_change) {
    var price = parseFloat(price_change);
    if (!price_change || isNaN(price) || price === 0) {
        return '';
    }

    return ' ' + (price > 0 ? '+' + price_change : price_change);
}

/**
 +----------------------------------------------------------
 * 文件盒子.文件上传
 +----------------------------------------------------------
 */
function attImage(att_id, id) {
    var target = $('#' + att_id + 'Html');
    var formID = att_id + id + 'Form';
    var fieldID = att_id + id + 'Field';

    if ($('#' + formID).length == 0) {
        var form =
           '<form action="' + route('admin.attribute.value', {}, { query: { act: 'image' } }) + '" id="' + formID + '" enctype="multipart/form-data" style="display:none">'
           + '<input id="' + fieldID + '" type="file" name="image">'
           + '<input type="hidden" name="value_id" value="' + id + '">'
         + '</form>';
        $("body").append(form);
    }

    var field = $('#' + fieldID);
    var target = target ? target : 'content'; // 适配旧版

    // 点击文件域
    field.click();

    // 选择文件后文件域状态改变，开始提交表单
    field.off("change").on("change",function() {
        // 还要判断文件域是否为空
        if($(this).val() != '') {
            $('#' + formID).ajaxForm({
                type: 'POST',
                data: {},
                dataType: 'json',
                success: function(resp) {
                    if (resp && resp.data && resp.data.html) {
                        target.html(resp.data.html);
                    }
                },
                error: function(xhr) {
                    var resp = xhr.responseJSON;
                    if (resp && resp.message) {
                        alert(resp.message);
                        if (resp.data && resp.data.html) {
                            target.html(resp.data.html);
                        }
                        return;
                    }
                    console.log('系统错误，请联系管理员！');
                },
                clearForm: true
            }).submit();

            // 每次执行后要清空文件域
            field.val('');
        }
   })
}

$(function() {
    attInitCategoryBox();
});
