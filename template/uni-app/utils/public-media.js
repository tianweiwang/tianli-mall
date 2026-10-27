// API public uploads and system images are relative to the server, not the Mini Program package.
// Keep this list finite: text, links, private references and business values are not media.
const mediaFields = new Set(['image', 'slider_image', 'small_image', 'video_link', 'logo']);

function publicMediaUrl(value, origin) {
  return typeof value === 'string' &&
    (value.startsWith('/uploads/') || value.startsWith('/statics/system_images/')) ? origin + value : value;
}

module.exports = function normalizePublicMedia(value, origin) {
  function visit(item, media) {
    if (typeof item === 'string') {
      return media ? publicMediaUrl(item, origin) : item;
    }
    if (Array.isArray(item)) return item.map(entry => visit(entry, media));
    if (item && typeof item === 'object') {
      const result = {};
      Object.keys(item).forEach(key => {
        Object.defineProperty(result, key, { value: visit(item[key], mediaFields.has(key)),
          enumerable: true, configurable: true, writable: true });
      });
      return result;
    }
    return item;
  }
  return visit(value, false);
};

module.exports.publicMediaUrl = publicMediaUrl;
