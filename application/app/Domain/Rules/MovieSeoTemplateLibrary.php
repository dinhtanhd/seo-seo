<?php

namespace App\Domain\Rules;

use App\Domain\Support\Access;
use App\Domain\Support\Json;
use Illuminate\Support\Facades\DB;

final class MovieSeoTemplateLibrary
{
    /** @return array<string, array{name: string, description: string, reference_brand: string, reference_url: string, definition: array}> */
    public function catalog(): array
    {
        $styles = [
            'movie-portal-v1' => ['Kênh phim · thương hiệu cuối', '爱奇艺', 'https://www.iqiyi.com/dianying_new', 'Theo nhịp tiêu đề kênh – nhóm tìm kiếm – xem phim – thương hiệu của trang tham khảo.', [
                'home' => ['电影频道-电影大全-电影在线观看-{{site.name}}', '{{site.name}}电影频道，汇集动作、喜剧、爱情、科幻等类型影片，浏览电影介绍、演员与分集，选择喜欢的电影在线观看。', '电影、电视剧、动漫和综艺，按分类找到想看的作品。', '电影,电影大全,电影在线观看,动作片,喜剧片,爱情片,科幻片,{{site.name}}'],
                'list' => ['{{category.name}}频道-{{category.name}}大全-在线观看-{{site.name}}', '{{site.name}}{{category.name}}频道，浏览{{category.name}}影片、剧情介绍和播放入口。', '从{{category.name}}分类选择影片，查看剧情后进入播放。'],
                'detail' => ['{{content.title}}-《{{kw.primary}}》[{{kw.secondary}}]-在线观看-{{site.name}}', '{{content.title}}在线观看，影片类型：{{content.genre}}。在{{site.name}}查看剧情介绍、演员和播放列表。相关内容：《{{kw.primary}}》[{{kw.secondary}}]。', '{{content.title}}，查看剧情与分集，继续浏览《{{kw.primary}}》[{{kw.secondary}}]。'],
                'play' => ['{{content.title}}-{{episode.title}}-《{{kw.primary}}》[{{kw.secondary}}]-在线观看-{{site.name}}', '正在播放{{content.title}}{{episode.title}}，可切换其他分集或返回影片介绍。相关内容：《{{kw.primary}}》[{{kw.secondary}}]，尽在{{site.name}}。', '当前播放：{{episode.title}}。影片相关内容：《{{kw.primary}}》[{{kw.secondary}}]。'],
                'search' => ['{{search.query}}-电影电视剧搜索-{{site.name}}', '在{{site.name}}搜索{{search.query}}，查找电影、电视剧、动漫和综艺，进入影片详情与播放列表。', '输入片名查找想看的作品。'],
            ]],
            'movie-library-v1' => ['Thương hiệu đầu · kênh phim', '腾讯视频', 'https://v.qq.com/channel/movie', 'Theo cấu trúc thương hiệu đứng đầu, tiếp đến kênh phim và cụm tìm kiếm của trang tham khảo.', [
                'home' => ['{{site.name}}-电影频道-电影电视剧大全-在线观看', '{{site.name}}电影频道，电影与电视剧按类型排列，动作、爱情、喜剧、科幻等影片一站浏览，选择作品即可查看剧情和播放列表。', '选电影，看剧集，从分类与搜索找到喜欢的内容。', '电影,电影大全,电视剧,电影在线观看,动作片,爱情片,喜剧片,科幻片,{{site.name}}电影'],
                'list' => ['{{site.name}}-{{category.name}}频道-{{category.name}}在线观看', '{{site.name}}{{category.name}}频道提供分类影片、剧情简介与播放入口，查找喜欢的{{category.name}}。', '浏览{{category.name}}，选择影片开始观看。'],
                'detail' => ['{{site.name}}-{{content.title}}-《{{kw.primary}}》[{{kw.secondary}}]-在线观看', '{{site.name}}为您呈现{{content.title}}的剧情介绍、演员与分集，类型：{{content.genre}}。浏览《{{kw.primary}}》[{{kw.secondary}}]相关影片，选择分集在线观看。', '想看{{content.title}}？从下方选择分集，也可浏览《{{kw.primary}}》[{{kw.secondary}}]相关内容。'],
                'play' => ['{{site.name}}-{{content.title}}-{{episode.title}}-《{{kw.primary}}》[{{kw.secondary}}]', '在{{site.name}}观看{{content.title}}{{episode.title}}，通过播放列表切换分集。更多《{{kw.primary}}》[{{kw.secondary}}]内容可从影片详情浏览。', '{{content.title}}{{episode.title}}，继续观看或查看《{{kw.primary}}》[{{kw.secondary}}]相关内容。'],
                'search' => ['{{site.name}}-{{search.query}}-电影电视剧搜索', '{{site.name}}搜索{{search.query}}，查看匹配的影片、剧情简介和分集入口。', '搜索片名，选择作品进入详情。'],
            ]],
            'tv-episodes-v1' => ['Thương hiệu đầu · phim và tập', '优酷', 'https://www.youku.com/ku/webmovie', 'Theo thứ tự thương hiệu – kênh phim – nhóm nội dung – xem trực tuyến của trang tham khảo.', [
                'home' => ['{{site.name}}-电影频道-电影大全-在线观看', '{{site.name}}电影频道，浏览动作、喜剧、科幻、悬疑等电影与剧集，了解剧情、演员和分集内容，从片单进入在线观看。', '电影与剧集分类浏览，找到想看的故事。', '电影,电影大全,电影在线观看,电视剧,经典电影,科幻电影,悬疑电影,{{site.name}}'],
                'list' => ['{{site.name}}-{{category.name}}-电影剧集在线观看', '{{site.name}}{{category.name}}片单，汇集影片与剧集，查看剧情介绍并选择分集在线观看。', '从分类片单进入影片，选择对应集数。'],
                'detail' => ['{{site.name}}-{{content.title}}-在线观看-《{{kw.primary}}》[{{kw.secondary}}]', '{{content.title}}在{{site.name}}的影片页面，提供剧情介绍、演职员及分集播放列表。继续发现《{{kw.primary}}》[{{kw.secondary}}]相关内容。', '了解{{content.title}}的故事，选择分集观看：《{{kw.primary}}》[{{kw.secondary}}]。'],
                'play' => ['{{site.name}}-{{content.title}}-{{episode.title}}-在线观看-《{{kw.primary}}》[{{kw.secondary}}]', '{{content.title}}{{episode.title}}在线观看，通过播放列表选择其他集数，在{{site.name}}继续浏览《{{kw.primary}}》[{{kw.secondary}}]。', '选择{{episode.title}}或其他分集，继续观看《{{kw.primary}}》[{{kw.secondary}}]相关内容。'],
                'search' => ['{{site.name}}-{{search.query}}-视频搜索', '在{{site.name}}搜索{{search.query}}，查找电影与剧集，打开影片查看分集。', '按片名搜索电影与剧集。'],
            ]],
            'animation-v1' => ['Kênh phim · tiêu đề ngắn', '哔哩哔哩', 'https://www.bilibili.com/movie/', 'Theo tiêu đề ngắn “电影 – thương hiệu” của kênh phim tham khảo; không tự nhận có tính năng đạn mạc.', [
                'home' => ['电影 - {{site.name}}', '{{site.name}}电影与动画频道，从电影、动画、动漫和剧集中寻找喜欢的故事，浏览剧情与创作者，选择分集观看。', '发现电影与动画，选择感兴趣的故事。', '电影,动画,动漫,剧集,电影频道,{{site.name}}'],
                'list' => ['{{category.name}} - {{site.name}}', '{{site.name}}的{{category.name}}分类，浏览影片、故事介绍与分集。', '浏览{{category.name}}中的故事，选择作品观看。'],
                'detail' => ['{{content.title}} - 《{{kw.primary}}》[{{kw.secondary}}] - {{site.name}}', '{{content.title}}的故事、演员与分集。类型：{{content.genre}}；地区：{{content.area}}。在{{site.name}}观看并探索《{{kw.primary}}》[{{kw.secondary}}]相关内容。', '认识{{content.title}}的故事与创作者：《{{kw.primary}}》[{{kw.secondary}}]。'],
                'play' => ['{{content.title}} {{episode.title}} - 《{{kw.primary}}》[{{kw.secondary}}] - {{site.name}}', '观看{{content.title}}{{episode.title}}，选择其他分集或返回故事介绍。相关内容：《{{kw.primary}}》[{{kw.secondary}}]。', '当前分集：{{episode.title}}。继续发现《{{kw.primary}}》[{{kw.secondary}}]。'],
                'search' => ['{{search.query}} - 搜索 - {{site.name}}', '在{{site.name}}搜索{{search.query}}，找到电影、动画与剧集。', '通过片名寻找喜欢的故事。'],
            ]],
            'variety-v1' => ['Tên phim · xem video · thương hiệu', '芒果TV', 'https://www.mgtv.com/h/713367.html', 'Theo cấu trúc tên tác phẩm – xem video – thương hiệu từ trang chi tiết đã thu thập.', [
                'home' => ['电影电视剧 - 视频在线观看 - {{site.name}}', '{{site.name}}汇集电影、电视剧和综艺节目，查看故事介绍、演员与已有分集，选择喜欢的内容在线观看。', '电影、剧集与节目，从这里开始观看。', '电影,电视剧,综艺,视频在线观看,{{site.name}}'],
                'list' => ['{{category.name}} - 视频在线观看 - {{site.name}}', '{{category.name}}视频在线观看，在{{site.name}}浏览分类内容、作品介绍和播放列表。', '选择{{category.name}}作品，查看介绍后进入播放。'],
                'detail' => ['{{content.title}} - 视频在线观看 - 《{{kw.primary}}》[{{kw.secondary}}] - {{site.name}}', '{{content.title}}视频在线观看，查看剧情、演职员与分集内容。在{{site.name}}继续浏览《{{kw.primary}}》[{{kw.secondary}}]。', '{{content.title}}的故事与分集：《{{kw.primary}}》[{{kw.secondary}}]。'],
                'play' => ['{{content.title}} {{episode.title}} - 视频在线观看 - 《{{kw.primary}}》[{{kw.secondary}}] - {{site.name}}', '{{content.title}}{{episode.title}}视频在线观看，切换分集并浏览影片介绍。相关内容：《{{kw.primary}}》[{{kw.secondary}}]。', '正在观看{{episode.title}}，相关内容：《{{kw.primary}}》[{{kw.secondary}}]。'],
                'search' => ['{{search.query}} - 视频搜索 - {{site.name}}', '在{{site.name}}搜索{{search.query}}，找到电影、剧集与综艺的播放入口。', '搜索片名或节目名称。'],
            ]],
            'genre-index-v1' => ['Kênh phim · thương hiệu cuối', '搜狐视频', 'https://tv.sohu.com/movie/', 'Theo cấu trúc kênh phim – thương hiệu ngắn gọn của trang tham khảo và các nhóm thể loại trong mô tả.', [
                'home' => ['电影频道 - {{site.name}}', '{{site.name}}电影频道，浏览华语、日韩、欧美等地区电影，按动作、爱情、喜剧、科幻等类型选择影片在线观看。', '按地区与类型浏览电影，选择想看的作品。', '电影,电影频道,电影在线观看,华语电影,日韩电影,欧美电影,{{site.name}}'],
                'list' => ['{{category.name}}频道 - {{site.name}}', '{{site.name}}{{category.name}}频道，浏览分类影片，了解剧情与演员，选择分集观看。', '浏览{{category.name}}分类，找到感兴趣的影片。'],
                'detail' => ['{{content.title}} - {{content.genre}} - 《{{kw.primary}}》[{{kw.secondary}}] - {{site.name}}', '{{content.title}}在线观看，影片类型：{{content.genre}}。在{{site.name}}了解剧情与演职员，并浏览《{{kw.primary}}》[{{kw.secondary}}]相关影片。', '影片类型：{{content.genre}}。更多相关内容：《{{kw.primary}}》[{{kw.secondary}}]。'],
                'play' => ['{{content.title}} - {{episode.title}} - 《{{kw.primary}}》[{{kw.secondary}}] - {{site.name}}', '{{content.title}}{{episode.title}}在线观看，可返回影片介绍，继续发现《{{kw.primary}}》[{{kw.secondary}}]相关内容。', '切换分集或继续浏览《{{kw.primary}}》[{{kw.secondary}}]。'],
                'search' => ['{{search.query}} - 电影搜索 - {{site.name}}', '在{{site.name}}搜索{{search.query}}，查找影片并按类型继续浏览。', '搜索影片名称，浏览所属分类。'],
            ]],
            'story-focus-v1' => ['Tiêu đề dùng gạch dưới', '乐视视频', 'https://movie.le.com/', 'Theo nhịp các cụm nội dung nối bằng gạch dưới và thương hiệu sau dấu gạch ngang của trang tham khảo.', [
                'home' => ['{{site.name}}电影_电影大全_电影在线观看 - {{site.name}}', '{{site.name}}电影频道，浏览喜剧、爱情、恐怖、微电影等影片，查看剧情介绍，按片名或分类找到喜欢的电影。', '从电影分类找到喜欢的故事，进入影片观看。', '{{site.name}}电影,电影大全,喜剧电影,爱情电影,恐怖电影,微电影,电影在线观看'],
                'list' => ['{{category.name}}_{{category.name}}电影_在线观看 - {{site.name}}', '{{site.name}}{{category.name}}分类，浏览影片简介、演员和播放入口，选择喜欢的故事。', '浏览{{category.name}}电影，查看故事与分集。'],
                'detail' => ['{{content.title}}_《{{kw.primary}}》_[{{kw.secondary}}]_在线观看 - {{site.name}}', '{{content.title}}剧情介绍与在线观看，查看演员与分集列表，继续浏览《{{kw.primary}}》[{{kw.secondary}}]相关电影。', '从{{content.title}}的故事开始，发现《{{kw.primary}}》[{{kw.secondary}}]相关内容。'],
                'play' => ['{{content.title}}_{{episode.title}}_《{{kw.primary}}》_[{{kw.secondary}}] - {{site.name}}', '{{content.title}}{{episode.title}}在线观看，通过分集列表继续观看，更多《{{kw.primary}}》[{{kw.secondary}}]内容可返回影片页查看。', '当前分集：{{episode.title}}。相关电影内容：《{{kw.primary}}》[{{kw.secondary}}]。'],
                'search' => ['{{search.query}}_电影搜索 - {{site.name}}', '在{{site.name}}搜索{{search.query}}，查找电影、剧情介绍和播放列表。', '按片名查找喜欢的电影。'],
            ]],
            'cast-credits-v1' => ['Thương hiệu ngắn · thông tin đoàn phim', '豆瓣电影', 'https://movie.douban.com/', 'Trang chủ giữ thương hiệu ngắn theo nguồn; trang phim thích ứng tên tác phẩm và thông tin đoàn phim, không thêm điểm hay đánh giá giả.', [
                'home' => ['{{site.name}}', '{{site.name}}提供电影与电视剧介绍，浏览剧情、导演、演员和分类影片，从作品名称发现感兴趣的电影。', '从影片、导演与演员，发现想看的电影。', '电影,经典电影,电视剧,美剧,电影介绍,导演,演员,{{site.name}}'],
                'list' => ['{{category.name}} ({{site.name}})', '{{site.name}}的{{category.name}}电影与剧集，查看剧情、导演和演员信息。', '浏览{{category.name}}作品，认识故事与创作者。'],
                'detail' => ['{{content.title}} ({{site.name}}) - 《{{kw.primary}}》[{{kw.secondary}}]', '{{content.title}}剧情与演职员。导演：{{content.director}}；演员：{{content.actors}}。查看分集并浏览《{{kw.primary}}》[{{kw.secondary}}]相关内容。', '导演：{{content.director}}。演员：{{content.actors}}。相关内容：《{{kw.primary}}》[{{kw.secondary}}]。', '{{content.title}},{{content.director}},{{content.actors}},《{{kw.primary}}》,[{{kw.secondary}}],{{site.name}}'],
                'play' => ['{{content.title}} {{episode.title}} ({{site.name}}) - 《{{kw.primary}}》[{{kw.secondary}}]', '{{content.title}}{{episode.title}}，切换分集或返回剧情与演职员介绍，继续浏览《{{kw.primary}}》[{{kw.secondary}}]。', '当前分集：{{episode.title}}。了解影片与《{{kw.primary}}》[{{kw.secondary}}]相关内容。'],
                'search' => ['搜索: {{search.query}} ({{site.name}})', '在{{site.name}}搜索{{search.query}}，查找电影、电视剧以及导演与演员信息。', '输入片名查找电影与剧集。'],
            ]],
            'production-record-v1' => ['Phim và phim bộ · gạch dài', '1905电影网', 'https://vip.1905.com/?fr=homeh5_menu_wow', 'Theo cấu trúc phim/phim bộ — nhóm nội dung – xem trực tuyến – thương hiệu của kênh tham khảo; metadata lấy từ nguồn phim.', [
                'home' => ['电影电视剧—电影大全-在线观看-{{site.name}}', '在{{site.name}}浏览电影与电视剧，按类型选择影片，了解年份、地区、语言和剧情，进入分集在线观看。', '电影与电视剧，从分类片单进入播放。', '电影,影视剧,在线观看,电影大全,影视大全,{{site.name}}'],
                'list' => ['{{category.name}}—电影电视剧-在线观看-{{site.name}}', '{{site.name}}{{category.name}}影片列表，了解剧情与出品信息，选择电影或剧集在线观看。', '浏览{{category.name}}影片，了解作品再选择播放。'],
                'detail' => ['{{content.title}}—《{{kw.primary}}》[{{kw.secondary}}]-在线观看-{{site.name}}', '{{content.title}}在线观看。年份：{{content.year}}；地区：{{content.area}}；语言：{{content.language}}。相关内容：《{{kw.primary}}》[{{kw.secondary}}]。', '年份：{{content.year}}。地区：{{content.area}}。语言：{{content.language}}。继续浏览《{{kw.primary}}》[{{kw.secondary}}]。'],
                'play' => ['{{content.title}}—{{episode.title}}-《{{kw.primary}}》[{{kw.secondary}}]-{{site.name}}', '{{content.title}}{{episode.title}}在线观看，切换分集或返回影片介绍。相关内容：《{{kw.primary}}》[{{kw.secondary}}]。', '选择分集继续观看，相关内容：《{{kw.primary}}》[{{kw.secondary}}]。'],
                'search' => ['{{search.query}}—电影电视剧搜索-{{site.name}}', '在{{site.name}}搜索{{search.query}}，查找影片剧情、出品信息和分集播放入口。', '通过片名搜索电影与电视剧。'],
            ]],
            'watch-navigation-v1' => ['Gợi ý phim · gạch dưới', 'PPTV聚力', 'https://sou.pptv.com/category/area_14_typeid_1_pn_12', 'Theo chuỗi gợi ý phim – phim tổng hợp – phân loại nối bằng gạch dưới, thương hiệu đứng cuối như trang tham khảo.', [
                'home' => ['好看的电影推荐_电影大全_电影分类-{{site.name}}', '{{site.name}}为您提供电影推荐、电影大全与分类片单，选择感兴趣的影片，查看剧情、演员和分集，继续在线观看。', '按分类寻找电影，打开影片选择分集。', '电影,电影推荐,电影大全,电影分类,电影在线观看,{{site.name}}'],
                'list' => ['{{category.name}}推荐_{{category.name}}大全-{{site.name}}', '{{site.name}}的{{category.name}}分类推荐，浏览影片与故事介绍，找到喜欢的电影。', '从{{category.name}}片单选择影片进入播放。'],
                'detail' => ['{{content.title}}_在线观看_《{{kw.primary}}》_[{{kw.secondary}}]-{{site.name}}', '{{site.name}}为您提供{{content.title}}的剧情介绍与分集播放入口，继续浏览《{{kw.primary}}》[{{kw.secondary}}]相关影片。', '选择{{content.title}}的分集，继续发现《{{kw.primary}}》[{{kw.secondary}}]。'],
                'play' => ['{{content.title}}_{{episode.title}}_《{{kw.primary}}》_[{{kw.secondary}}]-{{site.name}}', '观看{{content.title}}{{episode.title}}，可切换其他分集或返回影片介绍。相关内容：《{{kw.primary}}》[{{kw.secondary}}]。', '从播放列表继续观看，相关内容：《{{kw.primary}}》[{{kw.secondary}}]。'],
                'search' => ['{{search.query}}_电影搜索-{{site.name}}', '在{{site.name}}搜索{{search.query}}，浏览匹配影片，进入详情与播放列表。', '找到作品后，选择分集开始观看。'],
            ]],
            'concise-title-v1' => ['Thương hiệu tối giản', '咪咕视频', 'https://www.miguvideo.com/p/channel/b1c29dcd91f94a0289bf6295140f43b2', 'Trang chủ theo tiêu đề và mô tả chỉ có thương hiệu của trang tham khảo; các trang còn lại thích ứng ngắn gọn.', [
                'home' => ['{{site.name}}', '{{site.name}}', '电影、电视剧、动漫与综艺，选择喜欢的内容。', '{{site.name}},视频,视频网站,电影,电视剧,动漫,综艺'],
                'list' => ['{{category.name}}-{{site.name}}', '{{site.name}}{{category.name}}，浏览影片与分集播放入口。', '浏览{{category.name}}分类作品。'],
                'detail' => ['{{content.title}}-《{{kw.primary}}》[{{kw.secondary}}]-{{site.name}}', '{{content.title}}在线观看，查看剧情与分集。相关内容：《{{kw.primary}}》[{{kw.secondary}}]。', '选择分集，继续浏览《{{kw.primary}}》[{{kw.secondary}}]。'],
                'play' => ['{{content.title}} {{episode.title}}-《{{kw.primary}}》[{{kw.secondary}}]-{{site.name}}', '{{content.title}}{{episode.title}}在线观看。相关内容：《{{kw.primary}}》[{{kw.secondary}}]。', '{{episode.title}}，相关内容：《{{kw.primary}}》[{{kw.secondary}}]。'],
                'search' => ['{{search.query}}-搜索-{{site.name}}', '在{{site.name}}搜索{{search.query}}。', '输入片名搜索。'],
            ]],
            'editorial-guide-v1' => ['Thương hiệu · mạng video', 'AcFun', 'https://www.acfun.cn/', 'Theo cấu trúc thương hiệu + mạng video – khẩu đề của trang tham khảo; dùng mô tả nội dung phim thay lời hứa về cộng đồng chưa có.', [
                'home' => ['{{site.name}}视频网 - 电影电视剧动漫综艺', '{{site.name}}视频网，电影、电视剧、动漫与综艺分类浏览，发现感兴趣的故事，查看作品介绍并选择分集观看。', '发现有趣的故事，找到喜欢的影片。', '{{site.name}},视频,电影,电视剧,动画,动漫,综艺'],
                'list' => ['{{category.name}} - {{site.name}}视频网', '{{site.name}}视频网{{category.name}}分类，选择影片，了解故事与创作者，进入分集观看。', '从{{category.name}}发现喜欢的故事。'],
                'detail' => ['{{content.title}} - 《{{kw.primary}}》[{{kw.secondary}}] - {{site.name}}视频网', '{{content.title}}的故事与在线观看入口。类型：{{content.genre}}；导演：{{content.director}}。继续发现《{{kw.primary}}》[{{kw.secondary}}]。', '认识故事与创作者，继续浏览《{{kw.primary}}》[{{kw.secondary}}]。'],
                'play' => ['{{content.title}} {{episode.title}} - 《{{kw.primary}}》[{{kw.secondary}}] - {{site.name}}视频网', '在{{site.name}}视频网观看{{content.title}}{{episode.title}}，选择其他分集，继续浏览《{{kw.primary}}》[{{kw.secondary}}]相关内容。', '当前分集：{{episode.title}}。继续发现《{{kw.primary}}》[{{kw.secondary}}]。'],
                'search' => ['{{search.query}} - 搜索 - {{site.name}}视频网', '在{{site.name}}视频网搜索{{search.query}}，查找电影、电视剧与动漫。', '输入片名，发现感兴趣的故事。'],
            ]],
        ];

        $catalog = [];
        foreach ($styles as $key => [$name, $brand, $url, $description, $views]) {
            $pages = [];
            foreach ($views as $view => [$title, $summary, $intro]) {
                $heading = match ($view) {
                    'home' => '{{site.name}}', 'list' => '{{category.name}}', 'detail' => '{{content.title}}',
                    'play' => '{{content.title}} - {{episode.title}}', 'search' => '搜索：{{search.query}}',
                };
                $keywords = $views[$view][3] ?? match ($view) {
                    'home' => '{{site.name}},电影,电视剧,动画,综艺', 'list' => '{{category.name}},{{site.name}}',
                    'detail' => '{{content.title}},{{content.genre}},《{{kw.primary}}》,[{{kw.secondary}}],{{site.name}}',
                    'play' => '{{content.title}},{{episode.title}},《{{kw.primary}}》,[{{kw.secondary}}],{{site.name}}', 'search' => '{{search.query}},{{site.name}}',
                };
                $pages[$view] = ['slots' => ['title' => $this->expression($title, true), 'description' => $this->expression($summary),
                    'h1' => $this->expression($heading), 'intro' => $this->expression($intro), 'meta_keywords' => $this->expression($keywords)],
                    'managed_body_zone' => 'main-content'];
            }
            $catalog[$key] = ['name' => 'SEO phim · Tham khảo '.$brand.' · '.$name.' · 5 trang',
                'description' => $description.' Công thức thích ứng từ kiểu trang đã thu thập, không phải bản sao đã kiểm chứng đủ năm trang.',
                'reference_brand' => $brand, 'reference_url' => $url, 'definition' => ['page_templates' => $pages]];
        }

        return $catalog;
    }

    /** @return array{created: int, existing: int, skipped_deleted: int, ids: array<string, int>} */
    public function install(int $actorId): array
    {
        $this->authorize($actorId);
        $catalog = $this->catalog();
        foreach ($catalog as $entry) {
            SeoTemplateBundle::validate($entry['definition']);
        }

        return DB::transaction(function () use ($actorId, $catalog): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['movie-seo-template-library']);
            DB::table('system_settings')->where('id', 1)->sharedLock()->first();
            DB::table('users')->where('id', $actorId)->sharedLock()->first();
            $this->authorize($actorId);
            $result = ['created' => 0, 'existing' => 0, 'skipped_deleted' => 0, 'ids' => []];
            foreach ($catalog as $key => $entry) {
                $installed = DB::table('movie_seo_template_catalog')->where('stable_key', $key)->first();
                if ($installed) {
                    $template = DB::table('templates')->where('id', $installed->template_id)->sharedLock()->first();
                    if (($template?->deleted_at ?? null) !== null) {
                        $result['skipped_deleted']++;

                        continue;
                    }
                    $result['existing']++;
                    $result['ids'][$key] = (int) $installed->template_id;

                    continue;
                }
                $name = $this->availableName($entry['name']);
                $templateId = DB::table('templates')->insertGetId(['name' => $name]);
                $definition = $entry['definition'];
                $hash = Json::hash($definition);
                $versionId = DB::table('template_versions')->insertGetId(['template_id' => $templateId, 'version_no' => 1, 'schema_version' => 2,
                    'definition_json' => Json::encode($definition), 'hash' => $hash, 'created_by' => $actorId]);
                $slots = [];
                foreach ($definition['page_templates'] as $view => $profile) {
                    foreach ($profile['slots'] as $slot => $config) {
                        $slots[] = ['template_version_id' => $versionId, 'slot_name' => $view.':'.$slot, 'context' => $slot === 'intro' ? 'html' : 'text',
                            'token_expression' => $config['template'], 'options_json' => Json::encode($config)];
                    }
                }
                DB::table('template_slots')->insert($slots);
                DB::table('templates')->where('id', $templateId)->update(['current_version_id' => $versionId]);
                DB::table('movie_seo_template_catalog')->insert(['stable_key' => $key, 'template_id' => $templateId,
                    'initial_version_id' => $versionId, 'initial_hash' => $hash, 'created_by' => $actorId]);
                Access::audit('template.library.installed', $actorId, null, ['catalog_key' => $key, 'template_id' => $templateId, 'version_id' => $versionId]);
                $result['created']++;
                $result['ids'][$key] = $templateId;
            }

            return $result;
        }, 3);
    }

    private function authorize(int $actorId): void
    {
        abort_unless(DB::table('users')->where('id', $actorId)->value('is_global_admin'), 403);
    }

    private function availableName(string $name): string
    {
        $candidate = $name;
        $suffix = 1;
        while (DB::table('templates')->where('name', $candidate)->exists()) {
            $candidate = $name.' (thư viện '.$suffix++.')';
        }

        return $candidate;
    }

    /** @return array{enabled: bool, template: string, fallbacks: array<string, string>} */
    private function expression(string $template, bool $title = false): array
    {
        $optional = ['content.genre' => '待补充', 'content.year' => '待补充', 'content.area' => '待补充',
            'content.director' => '待补充', 'content.actors' => '待补充', 'content.language' => '待补充',
            'episode.title' => '分集页面', 'episode.index' => '', 'kw.primary' => '', 'kw.secondary' => '', 'search.query' => ''];
        $fallbacks = [];
        foreach ($optional as $token => $fallback) {
            if (str_contains($template, '{{'.$token.'}}')) {
                $fallbacks[$token] = $title ? '' : $fallback;
            }
        }

        return ['enabled' => true, 'template' => $template, 'fallbacks' => $fallbacks];
    }
}
