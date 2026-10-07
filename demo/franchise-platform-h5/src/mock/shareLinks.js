export function buildShareEntryPath(storeId, token) {
  return `/c/store/${encodeURIComponent(storeId)}/share-entry/${encodeURIComponent(token)}`;
}

export function buildShareEntryUrl(locationLike, storeId, token) {
  const pathname = locationLike.pathname.endsWith('/')
    ? locationLike.pathname
    : `${locationLike.pathname}/`;
  return `${locationLike.origin}${pathname}#${buildShareEntryPath(storeId, token)}`;
}
