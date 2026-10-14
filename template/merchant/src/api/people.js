import { request } from './request';
import { recordId } from '../domain/people';

// The seven existing merchantapi management routes; no platform API or guessed detail route.
export const peopleApi = {
  employees: (query) => request('GET', 'employees', query),
  createEmployee: (body) => request('POST', 'employees', body),
  updateEmployee: (id, body) => request('PUT', 'employees/' + recordId(id), body),
  setEmployeeStatus: (id, status) => request('PUT', 'employees/' + recordId(id) + '/status', { status }),
  roles: (query) => request('GET', 'roles', query),
  createRole: (body) => request('POST', 'roles', body),
  updateRole: (id, body) => request('PUT', 'roles/' + recordId(id), body),
};
